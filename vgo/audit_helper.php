<?php
// Minimal audit logging helper (no-op if AuditLogs table missing)

if (!function_exists('vgo_audit_table_exists')) {
    function vgo_audit_table_exists(mysqli $conn): bool {
        static $cached = null;
        if ($cached !== null) return $cached;
        $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'AuditLogs' LIMIT 1");
        if (!$stmt) {
            $cached = false;
            return $cached;
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $cached = ($res && $res->num_rows > 0);
        $stmt->close();
        return $cached;
    }
}

if (!function_exists('vgo_audit_log')) {
    /**
     * @param mysqli $conn
     * @param string $action
     * @param string|null $entityType
     * @param string|int|null $entityId
     * @param array $details
     * @param int|null $actorUserId
     * @param int|null $actorRoleId
     */
    function vgo_audit_log(mysqli $conn, string $action, ?string $entityType = null, $entityId = null, array $details = [], ?int $actorUserId = null, ?int $actorRoleId = null): void {
        try {
            if (!vgo_audit_table_exists($conn)) return;

            if ($actorUserId === null) {
                $actorUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            }
            if ($actorRoleId === null) {
                $actorRoleId = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : null;
            }

            $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : null;
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

            $entityIdStr = null;
            if ($entityId !== null) {
                $entityIdStr = is_string($entityId) ? $entityId : (string)$entityId;
                if (strlen($entityIdStr) > 64) {
                    $entityIdStr = substr($entityIdStr, 0, 64);
                }
            }

            $detailsJson = null;
            if (!empty($details)) {
                $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($detailsJson !== null && strlen($detailsJson) > 65000) {
                    $detailsJson = substr($detailsJson, 0, 65000);
                }
            }

            $stmt = $conn->prepare('INSERT INTO AuditLogs (actor_user_id, actor_role_id, action, entity_type, entity_id, details_json, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$stmt) return;

            $actorUserIdParam = $actorUserId !== null ? $actorUserId : null;
            $actorRoleIdParam = $actorRoleId !== null ? $actorRoleId : null;
            $stmt->bind_param(
                'iissssss',
                $actorUserIdParam,
                $actorRoleIdParam,
                $action,
                $entityType,
                $entityIdStr,
                $detailsJson,
                $ip,
                $ua
            );
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            // ignore audit failures
        }
    }
}
