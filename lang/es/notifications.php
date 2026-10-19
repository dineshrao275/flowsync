<?php

return [
    'task' => [
        'assigned' => ['line' => ':actor te asignó :key — :title', 'subject' => '[:key] Se te asignó :title'],
        'commented' => ['line' => ':actor comentó en :key — :title', 'subject' => '[:key] Nuevo comentario de :actor'],
        'status_changed' => ['line' => ':actor movió :key a :status', 'subject' => '[:key] Movida a :status'],
        'unblocked' => ['line' => ':actor desbloqueó :key — :title', 'subject' => '[:key] Desbloqueada'],
        'other' => ['line' => ':actor te envió una notificación de tarea', 'subject' => '[:key] Notificación de tarea'],
    ],
    'fallback' => ['task' => 'una tarea', 'key' => 'Tarea', 'status' => 'un nuevo estado', 'actor' => 'Alguien', 'project' => 'Tu proyecto'],
    'labels' => ['project' => 'Proyecto', 'task' => 'Tarea', 'comment' => 'Comentario', 'status' => 'Estado', 'unblocked_by' => 'Desbloqueada por', 'open_task' => 'Abrir tarea'],
    'digest' => [
        'subject' => 'Tu resumen de :app: :count novedad|Tu resumen de :app: :count novedades',
        'intro' => 'Esto ocurrió desde tu último correo:',
    ],
];
