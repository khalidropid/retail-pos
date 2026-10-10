<?php

/*
| Barcode label layouts. "roll" sizes print one label per page (thermal
| label printers); "sheet" layouts tile labels on A4 paper.
*/

return [
    'label_sizes' => [
        '50x25' => ['label' => 'ملصق 50×25 مم', 'width' => 50, 'height' => 25, 'type' => 'roll'],
        '40x30' => ['label' => 'ملصق 40×30 مم', 'width' => 40, 'height' => 30, 'type' => 'roll'],
        '38x25' => ['label' => 'ملصق 38×25 مم', 'width' => 38, 'height' => 25, 'type' => 'roll'],
        '58x40' => ['label' => 'ملصق 58×40 مم', 'width' => 58, 'height' => 40, 'type' => 'roll'],
        'a4_24' => ['label' => 'ورقة A4 (24 ملصق 70×37 مم)', 'width' => 70, 'height' => 37, 'type' => 'sheet', 'columns' => 3, 'rows' => 8],
        'a4_40' => ['label' => 'ورقة A4 (40 ملصق 52.5×29.7 مم)', 'width' => 52.5, 'height' => 29.7, 'type' => 'sheet', 'columns' => 4, 'rows' => 10],
    ],
    'max_labels' => 1000,
];
