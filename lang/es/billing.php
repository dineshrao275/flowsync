<?php

return [
    'price_change' => [
        'subject_up' => 'El precio de tu plan :plan cambia el :date',
        'subject_down' => 'El precio de tu plan :plan baja el :date',
        'greeting' => 'Hola, :organisation:',
        'increase' => 'Estamos actualizando el precio del plan :plan. A partir del :date — tu próxima renovación — se te cobrarán :new por :cycle en lugar de :old.',
        'decrease' => 'Buenas noticias: el precio del plan :plan baja. A partir del :date — tu próxima renovación — se te cobrarán :new por :cycle en lugar de :old.',
        'nothing_now' => 'No cambia nada hasta entonces: el periodo que ya pagaste no se ve afectado y hoy no se te cobra nada adicional.',
        'options' => 'Puedes revisar o cambiar tu plan, o cancelar antes de la renovación, en Suscripción dentro de FlowSync.',
        'link' => 'Gestiona tu suscripción: :url',
        'cycle_monthly' => 'mes',
        'cycle_annual' => 'año',
    ],
    'trial_ending' => [
        'subject' => 'Tu prueba de FlowSync termina el :date',
        'body' => 'La prueba gratuita de :organisation termina el :date. Elige un plan antes para seguir usando FlowSync sin interrupciones; tus datos están a salvo en cualquier caso.',
    ],
    'card_expiring' => [
        'subject' => 'La tarjeta de tu cuenta de FlowSync está por caducar',
        'body' => 'La tarjeta :brand terminada en :last4 caduca el :expires. Actualiza tu método de pago en Suscripción para evitar un cobro fallido.',
    ],
    'payment_action_required' => [
        'subject' => 'Se necesita una acción para completar tu pago de FlowSync',
        'body' => 'Tu banco pide confirmar el último pago de FlowSync. Abre Suscripción y usa el enlace de pago, o actualiza tu método de pago.',
    ],
    'plan_change_scheduled' => [
        'subject' => 'Tu cambio de plan está programado',
        'body' => 'Tu suscripción pasará de :from a :to el :date, cuando termine el periodo de facturación actual. Conservas tu plan actual hasta entonces y no se te cobrará dos veces.',
    ],
];
