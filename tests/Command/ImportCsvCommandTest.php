<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Decision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportCsvCommandTest extends KernelTestCase
{
    public function testImportsIsIdempotent(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM ' . Decision::class)->execute();

        $csv = "Dummy,Dummy,Dummy,Dummy,Dummy,Dummy,Dummy,Dummy,Dummy,Dummy,Dummy,Dummy\n"
            . "Date,Product,Department,Submitted by,Approved by,Clients type,Change,Comment,As is,To-be,Follow-up date,Actual result\n"
            . "01.01.2026,Leasing,Risk,Test User,,All,Raise cut-off from 800 to 810,,,,,\n"
            . "02.01.2026,Installment,Risk,Another User,,All,\"Change segment split 70/30\",,,,,\n";

        $file = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($file, $csv);

        $cmd = (new Application(static::$kernel))->find('app:import-csv');
        $tester = new CommandTester($cmd);
        $tester->execute(['file' => $file, '--encoding' => 'UTF-8']);
        $tester->assertCommandIsSuccessful();
        self::assertSame(2, $em->getRepository(Decision::class)->count([]));

        $tester->execute(['file' => $file, '--encoding' => 'UTF-8']);
        $tester->assertCommandIsSuccessful();
        self::assertSame(2, $em->getRepository(Decision::class)->count([]), 'Second import must be idempotent');

        unlink($file);
    }
}
