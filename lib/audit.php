<?php
// audit_log：登入、檢視個案、修改、審核、匯出都寫一筆（#2）。
// 寫入失敗要讓動作一起失敗——稽核不到的寫入動作，事後無從查證。
function audit(string $action, ?string $targetType = null, $targetId = null, array $detail = []): void {
    $u = current_user_or_null();
    $actor = $u ? ($u['email'] ?: $u['username']) : ($detail['actor'] ?? '(anonymous)');
    unset($detail['actor']);
    rdb()->prepare('INSERT INTO audit_log (user_id, actor, action, target_type, target_id, detail, ip) VALUES (?,?,?,?,?,?,?)')
        ->execute([$u['id'] ?? null, $actor, $action, $targetType, $targetId === null ? null : (string)$targetId,
                   $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, client_ip()]);
}

function client_ip(): ?string {
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
}
