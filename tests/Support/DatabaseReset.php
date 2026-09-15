<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;

trait DatabaseReset
{
    private function resetDatabase(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $manager->getConnection()->executeStatement('DELETE FROM webhook_event');
        $manager->clear();

        return $manager;
    }
}
