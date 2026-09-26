<?php

namespace Tests\Unit;

use App\Modules\Customers\Support\Cpf;
use App\Modules\Customers\Support\Email;
use App\Modules\Customers\Support\Phone;
use App\Modules\LegacyImport\Testing\FictitiousLegacyDatabase;
use PHPUnit\Framework\TestCase;

class NormalizersTest extends TestCase
{
    public function test_email(): void
    {
        $this->assertSame('ana@exemplo.test', Email::normalize('  Ana@Exemplo.TEST '));
        $this->assertNull(Email::normalize('joao@'));
        $this->assertNull(Email::normalize(''));
        $this->assertNull(Email::normalize(null));
    }

    public function test_telefone_brasileiro_em_e164(): void
    {
        $this->assertSame('+5511912345678', Phone::normalize('(11) 91234-5678'));
        $this->assertSame('+5511912345678', Phone::normalize('+55 11 91234-5678'));
        $this->assertSame('+5511912345678', Phone::normalize('011 91234 5678'));
        $this->assertSame('+551132345678', Phone::normalize('(11) 3234-5678'));
        $this->assertNull(Phone::normalize('123'));
        $this->assertNull(Phone::normalize('(10) 91234-5678'), 'DDD inexistente');
        $this->assertNull(Phone::normalize('(11) 81234-5678'), 'celular sem o 9');
        $this->assertNull(Phone::normalize('(11) 99999-9999'), 'sequencia repetida');
    }

    public function test_cpf_com_digitos_verificadores(): void
    {
        $valido = FictitiousLegacyDatabase::cpf(4);
        $this->assertSame(preg_replace('/\D/', '', $valido), Cpf::normalize($valido));
        $this->assertNull(Cpf::normalize('111.111.111-11'));
        $this->assertNull(Cpf::normalize('123.456.789-00'));
        $this->assertNull(Cpf::normalize('123'));
        $this->assertSame('123.***.***-09', Cpf::mask('12345678909'));
    }
}
