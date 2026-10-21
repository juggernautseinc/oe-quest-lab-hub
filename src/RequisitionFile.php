<?php

namespace Juggernaut\Quest\Module;

use Juggernaut\Quest\Module\Exceptions\QuestFileSystemException;

final class RequisitionFile
{
    /** Return the basename only, preserving the document-type markers used by callers. */
    public static function save(string $directory, string $documentType, string $bytes): string
    {
        $type = strtoupper(trim($documentType));
        $prefix = str_contains($type, 'ABN')
            ? (str_contains($type, 'REQ') ? 'labABN-Requisition' : 'labABN')
            : (str_contains($type, 'AOE') ? 'labAOE-Requisition' : 'labRequisition');
        $name = $prefix . '-' . bin2hex(random_bytes(16)) . '.pdf';
        $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
        // Exclusive creation also refuses an existing file or symlink on collision.
        $file = @fopen($path, 'xb');
        if ($file === false) {
            throw new QuestFileSystemException('Cannot create Quest requisition file.');
        }
        try {
            $length = strlen($bytes);
            for ($offset = 0; $offset < $length;) {
                $written = fwrite($file, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new QuestFileSystemException('Cannot write Quest requisition file.');
                }
                $offset += $written;
            }
            if (!fflush($file)) {
                throw new QuestFileSystemException('Cannot flush Quest requisition file.');
            }
        } catch (\Throwable $e) {
            fclose($file);
            unlink($path);
            throw $e;
        }
        fclose($file);
        return $name;
    }
}
