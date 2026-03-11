<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_subscribe_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('Template key, e.g. singbox, clash');
            $table->mediumText('content')->nullable()->comment('Template content');
            $table->timestamps();
        });

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscribe_templates');
    }

    private function seedDefaults(): void
    {
        $protocols = [
            'singbox' => [
                'resources/rules/custom.sing-box.json',
                'resources/rules/default.sing-box.json',
            ],
            'clash' => [
                'resources/rules/custom.clash.yaml',
                'resources/rules/default.clash.yaml',
            ],
            'clashmeta' => [
                'resources/rules/custom.clashmeta.yaml',
                'resources/rules/custom.clash.yaml',
                'resources/rules/default.clash.yaml',
            ],
            'stash' => [
                'resources/rules/custom.stash.yaml',
                'resources/rules/custom.clash.yaml',
                'resources/rules/default.clash.yaml',
            ],
            'surge' => [
                'resources/rules/custom.surge.conf',
                'resources/rules/default.surge.conf',
            ],
            'surfboard' => [
                'resources/rules/custom.surfboard.conf',
                'resources/rules/default.surfboard.conf',
            ],
        ];

        foreach ($protocols as $name => $fileFallbacks) {
            $content = DB::table('v2_settings')
                ->where('name', "subscribe_template_{$name}")
                ->value('value');

            if ($content === null || $content === '') {
                $content = '';
                foreach ($fileFallbacks as $file) {
                    $path = base_path($file);
                    if (!File::exists($path)) {
                        continue;
                    }

                    $content = File::get($path);
                    break;
                }
            }

            DB::table('v2_subscribe_templates')->insert([
                'name' => $name,
                'content' => $content,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
