"""Receptor SMTP para o ensaio de homologacao (Fase 13; homologacao.md).

Aceita qualquer mensagem e grava cada uma como .eml numa pasta, para conferir
conteudo e visual dos e-mails sem mandar nada para fora. Sem TLS e sem
autenticacao: so para 127.0.0.1, nunca exposto na rede.

Uso: python smtp-receptor.py <pasta> [porta=2525]
"""
import asyncio
import datetime
import pathlib
import sys

PASTA = pathlib.Path(sys.argv[1])
PORTA = int(sys.argv[2]) if len(sys.argv) > 2 else 2525
PASTA.mkdir(parents=True, exist_ok=True)
contador = 0


async def sessao(leitor: asyncio.StreamReader, escritor: asyncio.StreamWriter) -> None:
    global contador

    def responder(linha: str) -> None:
        escritor.write((linha + "\r\n").encode())

    responder("220 receptor-de-ensaio ESMTP")
    await escritor.drain()
    remetente, destinatarios = "", []
    while True:
        bruto = await leitor.readline()
        if not bruto:
            break
        comando = bruto.decode("utf-8", "replace").rstrip("\r\n")
        verbo = comando[:4].upper()
        if verbo in ("EHLO", "HELO"):
            responder("250-receptor-de-ensaio" if verbo == "EHLO" else "250 receptor-de-ensaio")
            if verbo == "EHLO":
                responder("250-8BITMIME")
                responder("250 SMTPUTF8")
        elif verbo == "MAIL":
            remetente, destinatarios = comando[10:].strip(), []
            responder("250 OK")
        elif verbo == "RCPT":
            destinatarios.append(comando[8:].strip())
            responder("250 OK")
        elif verbo == "DATA":
            responder("354 fim com <CRLF>.<CRLF>")
            await escritor.drain()
            linhas = []
            while True:
                linha = await leitor.readline()
                if linha in (b".\r\n", b".\n", b""):
                    break
                if linha.startswith(b".."):
                    linha = linha[1:]
                linhas.append(linha)
            contador += 1
            carimbo = datetime.datetime.now().strftime("%Y%m%d-%H%M%S-%f")
            arquivo = PASTA / f"{carimbo}-{contador:04d}.eml"
            arquivo.write_bytes(b"".join(linhas))
            print(f"recebido {arquivo.name} de {remetente} para {', '.join(destinatarios)}", flush=True)
            responder(f"250 OK id={contador}")
        elif verbo == "RSET":
            remetente, destinatarios = "", []
            responder("250 OK")
        elif verbo == "NOOP":
            responder("250 OK")
        elif verbo == "QUIT":
            responder("221 tchau")
            await escritor.drain()
            break
        else:
            responder("502 comando nao suportado")
        await escritor.drain()
    escritor.close()


async def principal() -> None:
    servidor = await asyncio.start_server(sessao, "127.0.0.1", PORTA)
    print(f"receptor SMTP de ensaio em 127.0.0.1:{PORTA}, gravando em {PASTA}", flush=True)
    async with servidor:
        await servidor.serve_forever()


asyncio.run(principal())
