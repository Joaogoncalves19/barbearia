<?php
// Confere, registro a registro, a amostra gravada pelo sistema ANTIGO (popular-antigo*.sh)
// depois da importacao na homologacao. Uso: php conferir-amostra.php <banco-novo.sqlite>
$db = new PDO('sqlite:'.$argv[1]);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$ok = 0; $falhas = [];
function v(PDO $db, string $sql, array $p = []) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function confere(string $nome, $esperado, $obtido) {
    global $ok, $falhas;
    if ($esperado === $obtido) { $ok++; echo "  ok  $nome = ".json_encode($obtido, JSON_UNESCAPED_UNICODE)."\n"; }
    else { $falhas[] = $nome; echo "  !!  $nome: esperado ".json_encode($esperado, JSON_UNESCAPED_UNICODE).", obtido ".json_encode($obtido, JSON_UNESCAPED_UNICODE)."\n"; }
}
echo "== Equipe\n";
confere('admin antigo virou proprietario', 'owner', v($db, "select role from users where username='ensaio-admin'"));
confere('barbeiro com login', 'professional', v($db, "select role from users where username='barbeiro.ensaio'"));
confere('profissional ligado ao login', 'Barbeiro Ensaio', v($db, "select p.display_name from professionals p join users u on u.id=p.user_id where u.username='barbeiro.ensaio'") ?: v($db, "select p.name from professionals p join users u on u.id=p.user_id where u.username='barbeiro.ensaio'"));
confere('expediente 7 dias', 7, (int) v($db, "select count(distinct weekday) from working_hours"));
confere('folga importada', 1, (int) v($db, "select count(*) from time_off"));
echo "== Catalogo\n";
confere('Corte R$ 45,00', 4500, (int) v($db, "select price_cents from services where name='Corte Ensaio'"));
confere('Barba R$ 35,50', 3550, (int) v($db, "select price_cents from services where name='Barba Ensaio'"));
confere('Pomada R$ 29,90', 2990, (int) v($db, "select price_cents from products where name='Pomada Ensaio'"));
confere('Pomada custo R$ 12,00', 1200, (int) v($db, "select cost_cents from products where name='Pomada Ensaio'"));
confere('estoque da pomada (10 + 5 - 2)', 13, (int) v($db, "select sum(quantity) from stock_movements m join products p on p.id=m.product_id where p.name='Pomada Ensaio'"));
confere('plano R$ 99,90', 9990, (int) v($db, "select price_cents from plan_versions v join plans p on p.id=v.plan_id where p.name='Plano Ensaio' order by v.id desc limit 1"));
echo "== Clientes\n";
confere('cliente 1 telefone E.164', '+5511911111111', v($db, "select phone from customers where email='cliente.um@ensaio.test'"));
confere('cliente 1 CPF', '52998224725', v($db, "select cpf from customers where email='cliente.um@ensaio.test'"));
confere('cliente 1 nascimento', '1990-05-10', substr((string) v($db, "select birth_date from customers where email='cliente.um@ensaio.test'"), 0, 10));
confere('anotacao do cliente 1', 1, (int) v($db, "select count(*) from customer_notes n join customers c on c.id=n.customer_id where c.email='cliente.um@ensaio.test'"));
confere('descadastro do cliente 2', 'revoked', v($db, "select marketing_email_consent from customers where email='cliente.dois@ensaio.test'"));
confere('supressao do e-mail', 1, (int) v($db, "select count(*) from email_suppressions where email='cliente.dois@ensaio.test'"));
confere('assinatura manual do cliente 2', 'active', v($db, "select s.status from subscriptions s join customers c on c.id=s.customer_id where c.email='cliente.dois@ensaio.test'"));
echo "== Agenda e atendimento\n";
$a1 = $db->query("select * from appointments where status='completed'")->fetch();
confere('1 concluido', true, (bool) $a1);
confere('total do concluido (45 + 35,50 + 2 x 29,90)', 14030, (int) $a1['total_cents']);
confere('itens do concluido', 3, (int) v($db, "select count(*) from appointment_items where appointment_id=?", [$a1['id']]));
confere('pagamento pix', 'pix', v($db, "select method from payments where attendance_id in (select id from attendances where appointment_id=?) or id in (select id from payments where amount_cents=14030) limit 1", [$a1['id']]));
confere('gorjeta R$ 10,00', 1000, (int) v($db, "select sum(tip_cents) from payments where amount_cents=14030"));
confere('futuros confirmados', 2, (int) v($db, "select count(*) from appointments where status='confirmed'"));
confere('cancelado pela barbearia', 1, (int) v($db, "select count(*) from appointments where status='cancelled'"));
confere('avulso sem cadastro mantido', 'Avulso Ensaio', v($db, "select customer_name from appointments where customer_id is null"));
confere('historico da agenda', 6, (int) v($db, "select count(*) from appointment_events"));
echo "== Promocoes e fidelidade\n";
confere('cupom ENSAIO10', 1, (int) v($db, "select count(*) from coupons where code='ENSAIO10'"));
confere('vale-presente R$ 50,00', 5000, (int) v($db, "select amount_cents from gift_cards"));
confere('pontos do cliente 1 (ajuste 3 + visita 1)', 4, (int) v($db, "select sum(points) from loyalty_entries l join customers c on c.id=l.customer_id where c.email='cliente.um@ensaio.test'"));
echo "== Financeiro e avaliacoes\n";
confere('despesa R$ 1.500,00', 150000, (int) v($db, "select amount_cents from expenses"));
confere('repasse R$ 32,20', 3220, (int) v($db, "select amount_cents from commission_payouts"));
confere('vale R$ 50,00', 5000, (int) v($db, "select amount_cents from advances"));
confere('avaliacao 5 estrelas', 5, (int) v($db, "select rating from reviews"));
echo "\n$ok conferidos, ".count($falhas)." divergencia(s)".($falhas ? ': '.implode('; ', $falhas) : '')."\n";
exit($falhas ? 1 : 0);
