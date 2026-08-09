<?php
// カスタマイズ版（入庫時間仕様）デモデータ投入スクリプト
// 使用: php scripts/seed_custom_demo.php        … 既定 data/parking.db へ投入
//       PARK_DB_PATH=/tmp/x.db php scripts/seed_custom_demo.php  … 任意のDBへ投入
// 統計: 2026-05-01〜2026-08-09（101日）/ 1日 1〜15 枚（決定的ランダム・シード 20260809）
//   入庫時間（entry_time）: 08:45〜16:30 / 発券時間（created_at）: 09:30〜17:05（発券は入庫以降）
//   同一日・同一分の発券時刻の重複なし（元版 seed_demo.php と同じ品質基準）
// 範囲内（2026-05-01〜08-09）の既存データは差し替え。範囲外は温存。冪等。

require_once __DIR__ . '/../lib/db.php';

$start = new DateTimeImmutable('2026-05-01', new DateTimeZone(APP_TZ));
$end   = new DateTimeImmutable('2026-08-09', new DateTimeZone(APP_TZ));
$days  = (int)$start->diff($end)->days + 1; // 101

mt_srand(20260809); // 決定的（同じ結果を再現）

$entryMinStart = 8 * 60 + 45;   // 入庫下限 08:45
$entryMinEnd   = 16 * 60 + 30;  // 入庫上限 16:30
$issueMinStart = 9 * 60 + 30;   // 発券下限 09:30
$issueMinEnd   = 17 * 60 + 5;   // 発券上限 17:05

$pdo = db_open();
$pdo->beginTransaction();
$del = $pdo->prepare('DELETE FROM records WHERE created_at >= ? AND created_at < ?');
$del->execute([$start->format('Y-m-d') . ' 00:00:00', $end->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
$ins = $pdo->prepare('INSERT INTO records (entry_time, created_at) VALUES (?, ?)');

$total = 0;
$minEntry = $entryMinEnd; $maxEntry = $entryMinStart;
$minIssue = $issueMinEnd; $maxIssue = $issueMinStart;
$minPerDay = 16; $maxPerDay = 0;
for ($d = 0; $d < $days; $d++) {
    $date = $start->modify('+' . $d . ' days')->format('Y-m-d');
    $n = mt_rand(1, 15);
    $minPerDay = min($minPerDay, $n); $maxPerDay = max($maxPerDay, $n);
    $used = [];
    for ($i = 0; $i < $n; $i++) {
        $eMin = mt_rand($entryMinStart, $entryMinEnd);              // 入庫 08:45〜16:30
        $iMin = mt_rand(max($eMin, $issueMinStart), $issueMinEnd);  // 発券 入庫以降・09:30〜17:05
        while (isset($used[$iMin])) {                               // 同一日・同一分の重複を回避
            $iMin++;
            if ($iMin > $issueMinEnd) { $iMin = max($eMin, $issueMinStart); }
        }
        $used[$iMin] = true;
        $entryTime = sprintf('%02d:%02d', intdiv($eMin, 60), $eMin % 60);
        $issueTime = sprintf('%02d:%02d', intdiv($iMin, 60), $iMin % 60);
        $ins->execute([$entryTime, $date . ' ' . $issueTime . ':00']);
        $minEntry = min($minEntry, $eMin); $maxEntry = max($maxEntry, $eMin);
        $minIssue = min($minIssue, $iMin); $maxIssue = max($maxIssue, $iMin);
        $total++;
    }
}
$pdo->commit();
$fmt = fn($m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
printf("投入完了: %d 件 / %d 日（%s〜%s） → %s\n", $total, $days, $start->format('Y-m-d'), $end->format('Y-m-d'), DB_PATH);
printf("入庫時間: %s〜%s / 発券時間: %s〜%s / 1日あたり: %d〜%d 枚\n",
    $fmt($minEntry), $fmt($maxEntry), $fmt($minIssue), $fmt($maxIssue), $minPerDay, $maxPerDay);
