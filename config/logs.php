<?php

declare(strict_types=1);

/*
 | NAPLÓ (Logs modul) — v1.1 · 7. modul.
 */
return [
    // A CSV-export felső korlátja (sor). Fölötte a fájl csonkolódik, és ezt jelzi is.
    'export_max_rows' => (int) env('LOGS_EXPORT_MAX_ROWS', 50000),

    // Exportálás percenként, felhasználónként.
    'export_per_minute' => (int) env('LOGS_EXPORT_PER_MINUTE', 5),
];
