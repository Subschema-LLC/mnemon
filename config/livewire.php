<?php

/*
 * Only what Mnemon changes. Livewire merges this over its own defaults one
 * top-level key at a time, so temporary_file_upload is given in full.
 */
return [

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),

        // Admin document uploads land here first. Livewire's own default is
        // 12 MB; this follows MNEMON_DOCUMENT_MAX_MB instead.
        'rules' => ['required', 'file', 'max:'.(max(1, (int) env('MNEMON_DOCUMENT_MAX_MB', 25)) * 1024)],

        'directory' => null,
        'middleware' => null,

        // Livewire's list without svg: an SVG is a document that can carry
        // script, so an uploaded one is never served for inline display.
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],

        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
