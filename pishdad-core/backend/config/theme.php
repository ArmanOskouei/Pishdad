<?php
/*
| ECO4 — ریشهٔ قالب‌های کد‌محور برای `pishdad-theme:make`.
|
| پیش‌فرضِ فرمان، پوشهٔ خواهرِ `backend` در چیدمانِ مخزن است
| (`pishdad-core/frontend/src/themes`). در استقراری که فرانت mount/همسایه نیست
| (مثلاً کانتینرِ فقط-بک‌اند)، `THEME_SCAFFOLD_PATH` آن را صریح می‌کند.
*/
return [
    'scaffold_path' => env('THEME_SCAFFOLD_PATH', ''),
];
