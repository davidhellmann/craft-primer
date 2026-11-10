<?php

use CraftCms\Cms\Database\Table;
use davidhellmann\primer\elements\Preset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Primer's one table: the Preset element's own columns. Preset values live in Craft's content and relations.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create(Preset::TABLE, function(Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('sectionId');
            $table->integer('typeId');
            $table->json('fieldUids')->nullable();
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');

            // Not unique: a section that doesn't propagate to every site has one preset per scope.
            $table->index(['sectionId', 'typeId']);
            $table->foreign('id')->references('id')->on(Table::ELEMENTS)->cascadeOnDelete();
            $table->foreign('sectionId')->references('id')->on(Table::SECTIONS)->cascadeOnDelete();
            $table->foreign('typeId')->references('id')->on(Table::ENTRYTYPES)->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Deleting the elements takes their rows, content and relations along.
        DB::table(Table::ELEMENTS)->where('type', Preset::class)->delete();
        Schema::dropIfExists(Preset::TABLE);
    }
};
