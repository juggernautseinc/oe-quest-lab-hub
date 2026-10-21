<?php

namespace Juggernaut\Quest\Module;

use OpenEMR\Common\Acl\AclMain;

/** Authorization for the module's administrator management pages only. */
final class ManagementAccess
{
    public static function requireAdmin(bool $json = false): void
    {
        if (AclMain::aclCheckCore('admin', 'users')) {
            return;
        }

        http_response_code(403);
        $message = xlt('Not authorized');
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
        } else {
            echo $message;
        }
        exit;
    }
}
