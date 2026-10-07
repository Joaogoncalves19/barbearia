"""ENSAIO DO RETORNO — relanca no sistema ANTIGO o que foi feito no novo depois
da virada, a partir dos CSV de `app:rollback-export`, pelas proprias acoes do
painel antigo (o que a recepcao faria a mao). Uso:
python virada-3-relancar.py <url-antigo> <pasta-exportado> <banco-antigo.sqlite> <senha-admin>
"""
import csv, http.cookiejar, re, sqlite3, sys, urllib.parse, urllib.request

base, pasta, banco, senha = sys.argv[1:5]
jar = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

class SemRedirecionar(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None

op_sem = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), SemRedirecionar())

def token(caminho):
    html = op.open(f'{base}/{caminho}').read().decode('utf-8', 'replace')
    return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)

def acao(nome, **campos):
    dados = {'action': nome, 'csrf_token': token('admin.php'), **campos}
    corpo = urllib.parse.urlencode(dados, doseq=True).encode()
    try:
        op_sem.open(f'{base}/admin_actions.php', corpo)
        destino = ''
    except urllib.error.HTTPError as e:
        destino = e.headers.get('Location', '')
    erro = urllib.parse.parse_qs(urllib.parse.urlparse(destino).query).get('error')
    print(f'   {"ERRO" if erro else "ok"} {nome}' + (f': {erro[0]}' if erro else ''))
    return not erro

def q(sql, *p):
    with sqlite3.connect(banco) as c:
        r = c.execute(sql, p).fetchone()
        return r[0] if r else None

def ler(nome):
    with open(f'{pasta}/{nome}.csv', encoding='utf-8-sig') as f:
        return list(csv.DictReader(f, delimiter=';'))

print('== RETORNO 7. Sistema antigo de volta: login do admin')
t = token('login.php')
op.open(f'{base}/login.php', urllib.parse.urlencode({'csrf_token': t, 'login_type': 'admin', 'username': 'ensaio-admin', 'password': senha}).encode())
painel = op.open(f'{base}/admin.php').read().decode('utf-8', 'replace')
print('   painel antigo abriu:', 'Painel Admin' in painel, '| agendamentos no antigo:', q('select count(*) from agendamentos'))

def ids(servicos_txt, barbeiro, email):
    nomes = [re.sub(r' x\d+.*$', '', s.strip()) for s in servicos_txt.split('+')]
    sv = [q('select id from servicos where nome=?', n) for n in nomes]
    return [s for s in sv if s], q('select id from barbeiros where nome=?', barbeiro), q('select id from clientes where lower(email)=?', (email or '').lower())

print('== RETORNO 8. Relancamento pela recepcao (CSV do sistema novo)')
atendidos = {a['Cliente'] + a['Concluído em'][:10] for a in ler('atendimentos') if a['Situação'] == 'completed'}
for ag in ler('agendamentos'):
    if ag['Situação'] in ('Cancelado',) or ag['Origem'] == 'walk_in':
        continue  # cancelado: nada a relancar; encaixe: entra pelo atendimento abaixo
    sv, br, cl = ids(ag['Serviços'], ag['Profissional'], ag['E-mail'])
    d, m, a = ag['Data'].split('/')
    print(f"   agendamento {ag['Código']} {ag['Data']} {ag['Hora']} {ag['Cliente']}")
    acao('salvar_agendamento_manual', manual_cliente_id=cl or '', manual_nome=ag['Cliente'], manual_telefone=ag['Telefone'].lstrip("'"),
         manual_servicos=','.join(sv), manual_data=f'{a}-{m}-{d}', manual_horario=ag['Hora'], manual_barbeiro=br)

for at in ler('atendimentos'):
    if at['Situação'] != 'completed':
        continue
    itens = [i.strip() for i in at['Itens'].split('+')]
    servicos = [i for i in itens if q('select id from servicos where nome=?', re.sub(r' x\d+.*$', '', i))]
    produtos = [i for i in itens if i not in servicos]
    sv, br, cl = ids(' + '.join(servicos), at['Profissional'], '')
    cl = q('select id from clientes where nome=?', at['Cliente'])
    data = at['Concluído em'][:10]; d, m, a = data.split('/')
    print(f"   atendimento {at['Código']} de {at['Concluído em']} ({at['Itens']}) total {at['Total']} gorjeta {at['Gorjeta']}")
    hora = None
    for h in ['18:00', '18:30', '19:00', '19:30', '20:30']:  # horario livre hoje (o real fica na observacao)
        if acao('salvar_agendamento_manual', manual_cliente_id=cl or '', manual_nome=at['Cliente'], manual_telefone=at['Telefone'].lstrip("'"),
                manual_servicos=','.join(sv), manual_data=f'{a}-{m}-{d}', manual_horario=h, manual_barbeiro=br):
            hora = h
            break
    agid = q('select id from agendamentos where data=? and hora=? and barbeiro_id=?', f'{a}-{m}-{d}', hora, br)
    for p in produtos:
        nome = re.sub(r' x\d+.*$', '', p); qtd = re.search(r' x(\d+)', p).group(1)
        acao('adicionar_produto', agendamento_id=agid, produto_id=q('select id from produtos where nome=?', nome), qtd_vendida=qtd)
    forma = (ler('pagamentos')[0]['Forma'] if ler('pagamentos') else 'outro')
    acao('fechar_comanda', agendamento_id=agid, gorjeta=at['Gorjeta'], forma_pagamento={'cash': 'dinheiro', 'pix': 'pix', 'debit_card': 'debito', 'credit_card': 'credito'}.get(forma, 'outro'))

print('   agendamentos no antigo agora:', q('select count(*) from agendamentos'),
      '| concluidos hoje:', q("select count(*) from agendamentos where status='concluido' and data=date('now','localtime')"))
