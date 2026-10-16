<?php
/**
 * AJAX handler for Option Presets (Size, Color, Material, etc.).
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'editor');

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? 'list';

try {
    if ($action === 'list') {
        $presets = db()->query(
            "SELECT id, name, display_type, position
             FROM option_presets
             ORDER BY position ASC, id ASC"
        )->fetchAll();

        $valuesByPreset = [];
        if ($presets) {
            $presetIds = array_map('intval', array_column($presets, 'id'));
            $placeholders = implode(',', array_fill(0, count($presetIds), '?'));
            $stmtVal = db()->prepare(
                "SELECT id, preset_id, value, swatch_hex, position
                 FROM option_preset_values
                 WHERE preset_id IN ($placeholders)
                 ORDER BY position ASC, id ASC"
            );
            $stmtVal->execute($presetIds);
            while ($v = $stmtVal->fetch()) {
                $valuesByPreset[(int)$v['preset_id']][] = [
                    'id'         => (int)$v['id'],
                    'value'      => $v['value'],
                    'swatch_hex' => $v['swatch_hex'],
                    'position'   => (int)$v['position'],
                ];
            }
        }

        $result = [];
        foreach ($presets as $p) {
            $pid = (int)$p['id'];
            $result[] = [
                'id'           => $pid,
                'name'         => $p['name'],
                'display_type' => $p['display_type'],
                'position'     => (int)$p['position'],
                'values'       => $valuesByPreset[$pid] ?? [],
            ];
        }

        echo json_encode(['ok' => true, 'presets' => $result]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();

        if ($action === 'save_preset') {
            $name        = trim((string)($_POST['name'] ?? ''));
            $displayType = trim((string)($_POST['display_type'] ?? 'button'));
            $valuesJson  = $_POST['values'] ?? '[]';
            $values      = is_array($valuesJson) ? $valuesJson : json_decode($valuesJson, true);

            if ($name === '') {
                echo json_encode(['ok' => false, 'error' => 'Preset name is required.']);
                exit;
            }
            if (!in_array($displayType, ['button', 'dropdown', 'swatch', 'radio'], true)) {
                $displayType = 'button';
            }
            if (!is_array($values)) {
                $values = [];
            }

            $pdo = db();
            $pdo->beginTransaction();

            $stmtCheck = $pdo->prepare("SELECT id FROM option_presets WHERE name = :n");
            $stmtCheck->execute([':n' => $name]);
            $presetId = (int)$stmtCheck->fetchColumn();

            if ($presetId > 0) {
                $stmtUpdate = $pdo->prepare("UPDATE option_presets SET display_type = :dt WHERE id = :id");
                $stmtUpdate->execute([':dt' => $displayType, ':id' => $presetId]);
            } else {
                $posStmt = $pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM option_presets");
                $nextPos = (int)$posStmt->fetchColumn();
                $stmtInsert = $pdo->prepare("INSERT INTO option_presets (name, display_type, position) VALUES (:n, :dt, :pos)");
                $stmtInsert->execute([':n' => $name, ':dt' => $displayType, ':pos' => $nextPos]);
                $presetId = (int)$pdo->lastInsertId();
            }

            // Replace values
            $pdo->prepare("DELETE FROM option_preset_values WHERE preset_id = :pid")->execute([':pid' => $presetId]);

            $stmtVal = $pdo->prepare(
                "INSERT INTO option_preset_values (preset_id, value, swatch_hex, position)
                 VALUES (:pid, :val, :hex, :pos)"
            );
            $pos = 1;
            foreach ($values as $v) {
                $valStr = is_array($v) ? trim((string)($v['value'] ?? '')) : trim((string)$v);
                $hexStr = is_array($v) ? trim((string)($v['swatch_hex'] ?? '')) : null;
                if ($valStr !== '') {
                    $stmtVal->execute([
                        ':pid' => $presetId,
                        ':val' => $valStr,
                        ':hex' => ($hexStr && preg_match('/^#[0-9a-fA-F]{6}$/', $hexStr)) ? $hexStr : null,
                        ':pos' => $pos++,
                    ]);
                }
            }

            $pdo->commit();
            log_activity('preset.save', "Saved option preset {$name}");
            echo json_encode(['ok' => true, 'preset_id' => $presetId]);
            exit;
        }

        if ($action === 'delete_preset') {
            $presetId = (int)($_POST['preset_id'] ?? 0);
            if ($presetId > 0) {
                db()->prepare("DELETE FROM option_presets WHERE id = :id")->execute([':id' => $presetId]);
                log_activity('preset.delete', "Deleted option preset #{$presetId}");
                echo json_encode(['ok' => true]);
                exit;
            }
            echo json_encode(['ok' => false, 'error' => 'Invalid preset ID.']);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
