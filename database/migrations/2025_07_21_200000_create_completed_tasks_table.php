<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('completed_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('assignee_id')->nullable()->index();
            $table->string('priority')->default('Medium');
            $table->string('status')->default('Completed')->index();
            $table->integer('progress')->default(100);
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->text('comments')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('reverted')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
            $table->foreign('assignee_id')->references('id')->on('users')->onDelete('set null');
        });
    }
    public function down()
    {
        Schema::dropIfExists('completed_tasks');
    }
}; 