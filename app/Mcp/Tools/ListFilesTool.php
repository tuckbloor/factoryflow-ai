<?php

namespace App\Mcp\Tools;

use App\Mcp\FileSandbox;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Lists supported filenames and sizes in the local sandbox. Read-only.')]
class ListFilesTool extends Tool
{
    public function handle(Request $request): Response
    {
        try {
            $files = app(FileSandbox::class)->files();

            return Response::text(json_encode(['total_files' => count($files), 'files' => $files], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (RuntimeException $e) {
            return Response::error($e->getMessage());
        }
    }
}
