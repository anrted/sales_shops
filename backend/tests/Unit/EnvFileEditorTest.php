<?php

namespace Tests\Unit;

use App\Services\EnvFileEditor;
use Tests\TestCase;

class EnvFileEditorTest extends TestCase
{
    public function test_it_updates_existing_values_and_appends_new_ones(): void
    {
        $directory = storage_path('framework/testing');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = tempnam($directory, 'env-editor-');
        file_put_contents($path, "APP_NAME=Discount Hub\nLENTA_DEVICE_ID=old-device\n");

        try {
            $editor = new EnvFileEditor($path);
            $editor->update([
                'LENTA_DEVICE_ID' => 'new-device',
                'LENTA_RAW_COOKIE_HEADER' => 'qrator_jsr=1; qrator_jsid=2',
            ]);

            $contents = str_replace("\r\n", "\n", (string) file_get_contents($path));

            $this->assertStringContainsString("APP_NAME=Discount Hub\n", $contents);
            $this->assertStringContainsString("LENTA_DEVICE_ID=new-device\n", $contents);
            $this->assertStringContainsString("LENTA_RAW_COOKIE_HEADER=\"qrator_jsr=1; qrator_jsid=2\"\n", $contents);
        } finally {
            @unlink($path);
        }
    }
}
