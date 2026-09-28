<?php
/**
 * ==== SISI HOSTING (Laravel) ====
 * database/migrations/xxxx_create_wa_outbox_table.php
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 20);
            $table->enum('tipe', ['text', 'image', 'document'])->default('text');
            $table->text('isi')->nullable();
            $table->string('media_url', 500)->nullable();
            $table->string('filename')->nullable();
            $table->string('kategori', 50)->nullable();
            $table->string('ref_berkas', 100)->nullable();
            $table->enum('status', ['pending', 'diproses', 'terkirim', 'sampai', 'dibaca', 'gagal', 'dibatalkan'])
                  ->default('pending');
            $table->string('wa_message_id', 120)->nullable();
            $table->string('error')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at', 'id'], 'idx_wa_outbox_pending');
            $table->index(['nomor', 'created_at'], 'idx_wa_outbox_nomor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_outbox');
    }
};
