<?php

namespace davidhellmann\primer\migrations;

use craft\db\Migration;
use craft\db\Table;
use craft\helpers\Db;
use davidhellmann\primer\elements\Preset;

/**
 * Primer's one table: the Preset element's own columns. Preset values live in Craft's content and relations.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable(Preset::TABLE, [
            'id' => $this->integer()->notNull(),
            'sectionId' => $this->integer()->notNull(),
            'typeId' => $this->integer()->notNull(),
            'fieldUids' => $this->json(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        // Not unique: a section that doesn't propagate to every site has one preset per scope.
        $this->createIndex(null, Preset::TABLE, ['sectionId', 'typeId']);
        $this->addForeignKey(null, Preset::TABLE, ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Preset::TABLE, ['sectionId'], Table::SECTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Preset::TABLE, ['typeId'], Table::ENTRYTYPES, ['id'], 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        // Deleting the elements takes their rows, content and relations along.
        Db::delete(Table::ELEMENTS, ['type' => Preset::class]);
        $this->dropTableIfExists(Preset::TABLE);

        return true;
    }
}
