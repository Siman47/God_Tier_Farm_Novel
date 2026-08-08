<?php
declare(strict_types=1);

$dictionaryFile = __DIR__ . '/THAI_TERMINOLOGY.md';
$loadError = null;
$markdown = '';

if (!is_readable($dictionaryFile)) {
    http_response_code(500);
    $loadError = 'ไม่พบไฟล์ THAI_TERMINOLOGY.md กรุณาวางไฟล์ไว้ในโฟลเดอร์เดียวกับ index.php';
} else {
    $markdown = (string) file_get_contents($dictionaryFile);
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function inlineMarkdown(string $text): string {
    $text = e(trim($text));
    $text = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $text) ?? $text;
    $text = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
    return $text;
}

function parseCells(string $line): array {
    $line = trim($line);
    $line = preg_replace('/^\||\|$/u', '', $line) ?? $line;
    return array_map('trim', explode('|', $line));
}

function isSeparator(string $line): bool {
    foreach (parseCells($line) as $cell) {
        if (!preg_match('/^:?-{3,}:?$/', $cell)) return false;
    }
    return true;
}

function slugify(string $title, int $index): string {
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', trim($title)) ?? '';
    $slug = trim(mb_strtolower($slug, 'UTF-8'), '-');
    return $slug !== '' ? $slug : 'section-' . $index;
}

function renderMarkdown(string $markdown): array {
    $lines = preg_split('/\R/u', $markdown) ?: [];
    $html = '';
    $toc = [];
    $section = '';
    $headingIndex = 0;
    $i = 0;

    while ($i < count($lines)) {
        $line = rtrim($lines[$i]);

        if (preg_match('/^(#{1,3})\s+(.+)$/u', $line, $match)) {
            $level = strlen($match[1]);
            $title = trim($match[2]);
            $headingIndex++;
            $id = slugify($title, $headingIndex);
            if ($level === 2) $section = $title;
            if ($level >= 2) $toc[] = ['level' => $level, 'title' => $title, 'id' => $id];
            $html .= sprintf('<h%d id="%s" class="anchor">%s</h%d>', $level, e($id), inlineMarkdown($title), $level);
            $i++;
            continue;
        }

        if (str_starts_with(trim($line), '|') && isset($lines[$i + 1]) && isSeparator($lines[$i + 1])) {
            $headers = parseCells($line);
            $i += 2;
            $rows = [];
            while ($i < count($lines)) {
                if (str_starts_with(trim($lines[$i]), '|')) {
                    $rows[] = parseCells($lines[$i]);
                    $i++;
                    continue;
                }

                if (trim($lines[$i]) === '') {
                    $nextRow = $i + 1;
                    while ($nextRow < count($lines) && trim($lines[$nextRow]) === '') {
                        $nextRow++;
                    }
                    if ($nextRow < count($lines) && str_starts_with(trim($lines[$nextRow]), '|')) {
                        $i = $nextRow;
                        continue;
                    }
                }

                break;
            }
            $html .= '<div class="table-wrap"><table data-section="' . e($section) . '"><thead><tr>';
            foreach ($headers as $header) $html .= '<th>' . inlineMarkdown($header) . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $cells) {
                $search = mb_strtolower(implode(' ', $cells) . ' ' . $section, 'UTF-8');
                $status = '';
                foreach ($cells as $cell) {
                    if (in_array(trim($cell), ['อนุมัติ', 'รอตรวจ', 'แก้ไขแล้ว', 'ไม่แปล', 'เสร็จแล้ว', 'ถัดไป'], true)) {
                        $status = trim($cell);
                    }
                }
                $html .= '<tr data-search="' . e($search) . '" data-status="' . e($status) . '">';
                foreach ($cells as $cell) {
                    $class = in_array(trim($cell), ['อนุมัติ', 'เสร็จแล้ว'], true) ? 'approved'
                        : (in_array(trim($cell), ['รอตรวจ', 'ถัดไป'], true) ? 'pending' : '');
                    $html .= '<td' . ($class ? '><span class="badge ' . $class . '">' : '>');
                    $html .= inlineMarkdown($cell);
                    if ($class) $html .= '</span>';
                    $html .= '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table><p class="empty-table" hidden>ไม่พบคำศัพท์ที่ตรงกับตัวกรอง</p></div>';
            continue;
        }

        if (preg_match('/^-\s+(.+)$/u', trim($line), $match)) {
            $html .= '<ul>';
            while ($i < count($lines) && preg_match('/^-\s+(.+)$/u', trim($lines[$i]), $item)) {
                $html .= '<li>' . inlineMarkdown($item[1]) . '</li>';
                $i++;
            }
            $html .= '</ul>';
            continue;
        }

        if (trim($line) !== '') {
            $paragraph = [trim($line)];
            $i++;
            while ($i < count($lines) && trim($lines[$i]) !== ''
                && !preg_match('/^(#{1,3})\s+|^-\s+/u', trim($lines[$i]))
                && !str_starts_with(trim($lines[$i]), '|')) {
                $paragraph[] = trim($lines[$i]);
                $i++;
            }
            $html .= '<p>' . inlineMarkdown(implode(' ', $paragraph)) . '</p>';
            continue;
        }
        $i++;
    }
    return [$html, $toc];
}

[$contentHtml, $toc] = renderMarkdown($markdown);
$termCount = preg_match_all('/^\|\s*[^|\r\n]+\s*\|/mu', $markdown) ?: 0;
$termCount = max(0, $termCount - substr_count($markdown, '|---'));
$completed = preg_match('/\|\s*บทที่สกัดคำศัพท์แล้ว\s*\|\s*([\d,]+)/u', $markdown, $m) ? $m[1] : '—';
$next = preg_match('/\|\s*บทถัดไป\s*\|\s*([^|]+)\|/u', $markdown, $m) ? trim($m[1]) : '—';
$updated = preg_match('/\|\s*อัปเดตล่าสุด\s*\|\s*([^|]+)\|/u', $markdown, $m) ? trim($m[1]) : '—';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>พจนานุกรมแปลไทย — ฟาร์มระดับเทพ</title>
<style>
:root{--bg:#f4f7f5;--card:#fff;--ink:#17231d;--muted:#65736b;--line:#dce5df;--green:#18794e;--green-soft:#e6f5ed;--amber:#9a6700;--amber-soft:#fff3cd;--shadow:0 10px 30px rgba(28,54,41,.08)}
[data-theme="dark"]{--bg:#101612;--card:#18201b;--ink:#edf5f0;--muted:#a6b4ab;--line:#303d35;--green:#55d295;--green-soft:#183c2a;--amber:#ffd166;--amber-soft:#493b16;--shadow:none}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--ink);font-family:"Noto Sans Thai","Tahoma",system-ui,sans-serif;line-height:1.65}
header{background:linear-gradient(135deg,#113c2b,#1b6848);color:#fff;padding:38px 24px 30px}.hero{max-width:1400px;margin:auto}.eyebrow{font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;opacity:.78}.hero h1{margin:.2rem 0 .4rem;font-size:clamp(1.8rem,4vw,3.2rem);line-height:1.15}.hero p{margin:0;opacity:.82}
.stats{display:flex;gap:12px;flex-wrap:wrap;margin-top:22px}.stat{min-width:150px;padding:12px 16px;border:1px solid #ffffff32;background:#ffffff12;border-radius:14px}.stat strong{display:block;font-size:1.25rem}.stat span{font-size:.8rem;opacity:.8}
.layout{max-width:1400px;margin:0 auto;display:grid;grid-template-columns:260px minmax(0,1fr);gap:24px;padding:24px}
.sidebar{position:sticky;top:16px;align-self:start;max-height:calc(100vh - 32px);overflow:auto;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;box-shadow:var(--shadow)}
.sidebar b{display:block;padding:5px 8px 10px}.sidebar a{display:block;color:var(--muted);text-decoration:none;padding:7px 9px;border-radius:8px;font-size:.9rem}.sidebar a:hover{background:var(--green-soft);color:var(--green)}.sidebar a.sub{padding-left:20px;font-size:.83rem}
main{min-width:0}.toolbar{position:sticky;top:0;z-index:5;display:flex;gap:10px;flex-wrap:wrap;background:color-mix(in srgb,var(--bg) 88%,transparent);backdrop-filter:blur(10px);padding:0 0 16px}
input,select,button{font:inherit;border:1px solid var(--line);border-radius:11px;background:var(--card);color:var(--ink);padding:10px 13px}input{flex:1;min-width:240px}button{cursor:pointer}.result{align-self:center;color:var(--muted);font-size:.86rem}
article{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:clamp(18px,4vw,42px);box-shadow:var(--shadow)}h1,h2,h3{line-height:1.28}article h1{display:none}article h2{margin:2.2rem 0 .8rem;padding-top:.6rem;border-top:1px solid var(--line);font-size:1.45rem}article h2:first-of-type{margin-top:0;border-top:0}article h3{font-size:1.07rem;margin-top:1.6rem}p,li{color:var(--ink)}code{background:var(--bg);border:1px solid var(--line);border-radius:5px;padding:.08em .35em}
.table-wrap{margin:14px 0 26px;overflow-x:auto;border:1px solid var(--line);border-radius:12px}table{width:100%;border-collapse:collapse;font-size:.9rem}th,td{text-align:left;vertical-align:top;padding:11px 13px;border-bottom:1px solid var(--line)}th{position:sticky;top:0;background:var(--bg);white-space:nowrap}tr:last-child td{border-bottom:0}tbody tr:hover{background:var(--green-soft)}
.badge{display:inline-block;border-radius:999px;padding:2px 9px;white-space:nowrap}.approved{color:var(--green);background:var(--green-soft)}.pending{color:var(--amber);background:var(--amber-soft)}.empty-table{text-align:center;color:var(--muted);padding:18px}.notice{background:#fff1f1;color:#922;padding:18px;border-radius:12px;border:1px solid #e8baba}
mark{background:#ffe98f;color:#201a00;border-radius:3px}.footer{text-align:center;color:var(--muted);font-size:.8rem;padding:20px}
@media(max-width:850px){.layout{display:block;padding:14px}.sidebar{position:static;max-height:220px;margin-bottom:14px}.toolbar{top:0;padding-top:8px}article{border-radius:14px}th,td{min-width:130px}.hero{padding:0}.stats{display:grid;grid-template-columns:1fr 1fr}.stat{min-width:0}}
@media print{header,.sidebar,.toolbar,.footer{display:none}.layout{display:block;padding:0}article{border:0;box-shadow:none;padding:0}.table-wrap{overflow:visible}tr[hidden]{display:table-row!important}}
</style>
</head>
<body>
<header><div class="hero"><div class="eyebrow">Single Source of Truth</div><h1>พจนานุกรมแปลไทย — ฟาร์มระดับเทพ</h1><p>ข้อมูลทั้งหมดอ่านโดยตรงจาก THAI_TERMINOLOGY.md</p>
<div class="stats"><div class="stat"><strong><?= e($completed) ?></strong><span>บทที่สกัดแล้ว</span></div><div class="stat"><strong><?= e($next) ?></strong><span>บทถัดไป</span></div><div class="stat"><strong><?= e((string)$termCount) ?></strong><span>แถวข้อมูลโดยประมาณ</span></div><div class="stat"><strong><?= e($updated) ?></strong><span>อัปเดตล่าสุด</span></div></div></div></header>
<div class="layout">
<nav class="sidebar" aria-label="สารบัญ"><b>หมวดหมู่</b><?php foreach ($toc as $item): ?><a class="<?= $item['level'] === 3 ? 'sub' : '' ?>" href="#<?= e($item['id']) ?>"><?= e($item['title']) ?></a><?php endforeach; ?></nav>
<main><div class="toolbar"><input id="search" type="search" placeholder="ค้นหาภาษาจีน พินอิน ภาษาไทย หรือหมายเหตุ…" autocomplete="off"><select id="status" aria-label="กรองสถานะ"><option value="">ทุกสถานะ</option><option>อนุมัติ</option><option>รอตรวจ</option><option>แก้ไขแล้ว</option><option>ไม่แปล</option><option>เสร็จแล้ว</option><option>ถัดไป</option></select><button id="theme" type="button" title="สลับธีม">◐ ธีม</button><button type="button" onclick="window.print()">พิมพ์</button><span class="result" id="result"></span></div>
<article><?php if ($loadError): ?><div class="notice"><?= e($loadError) ?></div><?php else: ?><?= $contentHtml ?><?php endif; ?></article></main>
</div><div class="footer">ไฟล์นี้ใช้สำหรับการอ่านเท่านั้น — แก้ไขคำศัพท์ใน THAI_TERMINOLOGY.md</div>
<script>
const search=document.querySelector('#search'),status=document.querySelector('#status'),result=document.querySelector('#result');
function filterRows(){const q=search.value.trim().toLocaleLowerCase('th'),s=status.value;let visible=0,total=0;
document.querySelectorAll('tbody tr').forEach(row=>{total++;const show=(!q||row.dataset.search.includes(q))&&(!s||row.dataset.status===s);row.hidden=!show;if(show)visible++});
document.querySelectorAll('.table-wrap').forEach(w=>{const rows=[...w.querySelectorAll('tbody tr')];if(!rows.length)return;const empty=rows.every(r=>r.hidden);w.querySelector('.empty-table').hidden=!empty});
result.textContent=(q||s)?'แสดง '+visible+' จาก '+total+' แถว':''}
search.addEventListener('input',filterRows);status.addEventListener('change',filterRows);
const root=document.documentElement,saved=localStorage.getItem('dict-theme');if(saved)root.dataset.theme=saved;
document.querySelector('#theme').addEventListener('click',()=>{const next=root.dataset.theme==='dark'?'light':'dark';root.dataset.theme=next;localStorage.setItem('dict-theme',next)});
</script>
</body></html>
