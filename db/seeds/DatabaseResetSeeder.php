<?php

require_once __DIR__ . '/../AbstractCapsuleSeeder.php';
use Illuminate\Database\Capsule\Manager as Capsule;

class DatabaseResetSeeder extends AbstractCapsuleSeeder
{
    /**
     * Run Method.
     *
     * Write your database seeder using this method.
     *
     * More information on writing seeders is available here:
     * http://docs.phinx.org/en/latest/seeding.html
     */
    public function run()
    {
        $this->initCapsule();
//        $this->truncateTable('users');
//        $this->truncateTable('payments');
        $this->truncateTable('activity_report');
        $this->truncateTable('consumers');
        $this->truncateTable('deliveries');
        $this->truncateTable('delivery_item');
        $this->truncateTable('events');
        $this->truncateTable('inventory_item');
        $this->truncateTable('lendings');
        $this->truncateTable('project_user');
        \Api\Model\User::query()->delete();
        \Api\Model\Payment::query()->delete();
        \Api\Model\Membership::query()->delete();
        \Api\Model\Reservation::query()->delete();
        \Api\Model\Tool::query()->delete();

    }

    private function truncateTable($tableName): void
    {
        $table = $this->table($tableName);
        $table->truncate();
    }
}
