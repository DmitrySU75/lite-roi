<?php
/**
 * litepms-webhook.php
 * 
 * Обработчик вебхуков LitePMS → Битрикс24
 * 
 * Формат входных данных: application/x-www-form-urlencoded
 * 
 * @version 1.1
 */

// ============================================================
// 1. НАСТРОЙКИ
// ============================================================

$bitrixWebhookUrl   = 'https://contact-center.bitrix24.ru/rest/1183/lysxly5aafgtv6r5/';
$logFile            = __DIR__ . '/litepms-webhook.log';
$processedFile      = __DIR__ . '/litepms-processed.log';
$leadEntityTypeId   = 1; // 1 = Лид

// Пользовательские поля — ID брони и номер брони
$bookingIdField     = 'ufCrm_1790163178';
$userBookingIdField = 'ufCrm_1790163998';

// Пользовательские поля — рекламные параметры
$roistatField       = 'ufCrmRoistat';         // Roistat
$yclidField         = 'ufCrm_1790232672';     // Yclid
$ymClidField        = 'ufCrm_1790232703';     // YmClid
$etextField         = 'ufCrm_1790232726';     // Etext

// Режим отладки: true — только логировать, не создавать лиды
$DRY_RUN = false;

// Максимальный размер лог-файла (10 МБ)
$maxLogSize = 10 * 1024 * 1024;

// ============================================================
// 2. ПОЛУЧАЕМ ДАННЫЕ ОТ LitePMS
// ============================================================

$rawData = file_get_contents('php://input');

writeLog($logFile, 'IN: ' . $rawData, $maxLogSize);

// LitePMS отправляет данные в формате application/x-www-form-urlencoded
parse_str($rawData, $data);

if (empty($data)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

$eventType     = $data['type'] ?? '';
$bookingId     = $data['booking_id'] ?? null;
$userBookingId = $data['user_booking_id'] ?? null;

// ============================================================
// 3. ЗАЩИТА ОТ ДУБЛЕЙ — УРОВЕНЬ 1: ИДЕМПОТЕНТНОСТЬ СОБЫТИЯ
// ============================================================

$eventHashSource = $eventType . '|' . ($bookingId ?? '') . '|';

if ($eventType === 'edit_booking' && !empty($data['last_update'])) {
    $eventHashSource .= $data['last_update'];
} elseif ($eventType === 'booking_module_payment') {
    $eventHashSource .= ($data['invoice_id'] ?? '') . '|' . ($data['price'] ?? '');
} else {
    $eventHashSource .= $data['date'] ?? '';
}

$eventHash = md5($eventHashSource);

if (isEventProcessed($processedFile, $eventHash)) {
    writeLog($logFile, 'DUPLICATE EVENT SKIPPED: ' . $eventHash, $maxLogSize);
    http_response_code(200);
    echo 'OK';
    exit;
}

// ============================================================
// 4. ОБРАБОТКА СОБЫТИЙ
// ============================================================

$result = null;

switch ($eventType) {

    // --------------------------------------------------------
    // СОЗДАНИЕ БРОНИ → СОЗДАЁМ ЛИД
    // --------------------------------------------------------
    case 'new_booking':
    case 'booking_module':
    case 'channel_manager_booking':

        $existingLeadId = findExistingLead(
            $bookingId, $userBookingId,
            $leadEntityTypeId, $bookingIdField, $userBookingIdField,
            $bitrixWebhookUrl, $logFile, $maxLogSize
        );

        if ($existingLeadId) {
            writeLog($logFile, 'LEAD ALREADY EXISTS (ID: ' . $existingLeadId . ') for booking_id=' . $bookingId, $maxLogSize);

            $fields = buildLeadFields(
                $data, $bookingIdField, $userBookingIdField,
                $roistatField, $yclidField, $ymClidField, $etextField
            );
            unset($fields['sourceId'], $fields['sourceDescription']);

            if (!$DRY_RUN) {
                $result = callBitrix('crm.item.update', [
                    'entityTypeId' => $leadEntityTypeId,
                    'id' => $existingLeadId,
                    'fields' => $fields
                ], $bitrixWebhookUrl, $logFile, $maxLogSize);
            }
        } else {
            $fields = buildLeadFields(
                $data, $bookingIdField, $userBookingIdField,
                $roistatField, $yclidField, $ymClidField, $etextField
            );

            if ($DRY_RUN) {
                writeLog($logFile, 'DRY RUN: would create lead: ' . json_encode($fields, JSON_UNESCAPED_UNICODE), $maxLogSize);
            } else {
                $result = callBitrix('crm.item.add', [
                    'entityTypeId' => $leadEntityTypeId,
                    'fields' => $fields
                ], $bitrixWebhookUrl, $logFile, $maxLogSize);
            }
        }
        break;

    // --------------------------------------------------------
    // ПРЕДВАРИТЕЛЬНАЯ ЗАЯВКА → СОЗДАЁМ ЛИД
    // --------------------------------------------------------
    case 'booking_module_request':
        $phone = $data['phone'] ?? '';
        if (!empty($phone)) {
            $dupCheck = callBitrix('crm.item.list', [
                'entityTypeId' => $leadEntityTypeId,
                'filter' => [
                    '=PHONE' => $phone,
                    '>DATE_CREATE' => date('c', strtotime('-1 hour'))
                ],
                'select' => ['id']
            ], $bitrixWebhookUrl, $logFile, $maxLogSize);

            if (!empty($dupCheck['result']['items'][0]['id'])) {
                writeLog($logFile, 'REQUEST DUPLICATE SKIPPED for phone=' . $phone, $maxLogSize);
                break;
            }
        }

        $fields = [
            'title' => 'Заявка на бронь: ' . ($data['name'] ?? 'Без имени'),
            'name' => $data['name'] ?? '',
            'comments' => "=== Предварительная заявка из LitePMS ===\n"
                . "Дата: " . ($data['date'] ?? '') . "\n"
                . "Комментарий: " . ($data['comment'] ?? '—'),
            'sourceId' => 'LITEPMS',
            'sourceDescription' => 'Предварительная заявка LitePMS',
        ];

        if (!empty($phone)) {
            $fields['fm'] = [['typeId' => 'PHONE', 'valueType' => 'WORK', 'value' => $phone]];
        }

        if ($DRY_RUN) {
            writeLog($logFile, 'DRY RUN: would create request lead: ' . json_encode($fields, JSON_UNESCAPED_UNICODE), $maxLogSize);
        } else {
            $result = callBitrix('crm.item.add', [
                'entityTypeId' => $leadEntityTypeId,
                'fields' => $fields
            ], $bitrixWebhookUrl, $logFile, $maxLogSize);
        }
        break;

    // --------------------------------------------------------
    // ИЗМЕНЕНИЕ БРОНИ → ОБНОВЛЯЕМ ЛИД
    // --------------------------------------------------------
    case 'edit_booking':
        $leadId = findExistingLead(
            $bookingId, $userBookingId,
            $leadEntityTypeId, $bookingIdField, $userBookingIdField,
            $bitrixWebhookUrl, $logFile, $maxLogSize
        );

        if ($leadId) {
            $fields = buildLeadFields(
                $data, $bookingIdField, $userBookingIdField,
                $roistatField, $yclidField, $ymClidField, $etextField
            );
            unset($fields['sourceId'], $fields['sourceDescription']);

            if (!$DRY_RUN) {
                $result = callBitrix('crm.item.update', [
                    'entityTypeId' => $leadEntityTypeId,
                    'id' => $leadId,
                    'fields' => $fields
                ], $bitrixWebhookUrl, $logFile, $maxLogSize);
            } else {
                writeLog($logFile, 'DRY RUN: would update lead #' . $leadId, $maxLogSize);
            }
        } else {
            $fields = buildLeadFields(
                $data, $bookingIdField, $userBookingIdField,
                $roistatField, $yclidField, $ymClidField, $etextField
            );

            if (!$DRY_RUN) {
                $result = callBitrix('crm.item.add', [
                    'entityTypeId' => $leadEntityTypeId,
                    'fields' => $fields
                ], $bitrixWebhookUrl, $logFile, $maxLogSize);
            }
        }
        break;

    // --------------------------------------------------------
    // УДАЛЕНИЕ БРОНИ → ОТМЕЧАЕМ ЛИД КАК ОТМЕНЁННЫЙ
    // --------------------------------------------------------
    case 'delete_booking':
        $leadId = findExistingLead(
            $bookingId, $userBookingId,
            $leadEntityTypeId, $bookingIdField, $userBookingIdField,
            $bitrixWebhookUrl, $logFile, $maxLogSize
        );

        if ($leadId) {
            $lead = callBitrix('crm.item.get', [
                'entityTypeId' => $leadEntityTypeId,
                'id' => $leadId
            ], $bitrixWebhookUrl, $logFile, $maxLogSize);

            $currentComments = $lead['result']['item']['comments'] ?? '';
            $currentTitle = $lead['result']['item']['title'] ?? '';

            if (!$DRY_RUN) {
                $result = callBitrix('crm.item.update', [
                    'entityTypeId' => $leadEntityTypeId,
                    'id' => $leadId,
                    'fields' => [
                        'title' => 'ОТМЕНЕНО: ' . $currentTitle,
                        'comments' => $currentComments . "\n\n=== БРОНЬ УДАЛЕНА В LitePMS ===\n"
                            . "Дата удаления: " . date('Y-m-d H:i:s'),
                        'statusId' => 'JUNK'
                    ]
                ], $bitrixWebhookUrl, $logFile, $maxLogSize);
            } else {
                writeLog($logFile, 'DRY RUN: would mark lead #' . $leadId . ' as JUNK', $maxLogSize);
            }
        }
        break;

    // --------------------------------------------------------
    // ОНЛАЙН-ПЛАТЁЖ → ДОБАВЛЯЕМ ИНФОРМАЦИЮ В ЛИД
    // --------------------------------------------------------
    case 'booking_module_payment':
        $leadId = findExistingLead(
            $bookingId, $userBookingId,
            $leadEntityTypeId, $bookingIdField, $userBookingIdField,
            $bitrixWebhookUrl, $logFile, $maxLogSize
        );

        if ($leadId) {
            $lead = callBitrix('crm.item.get', [
                'entityTypeId' => $leadEntityTypeId,
                'id' => $leadId
            ], $bitrixWebhookUrl, $logFile, $maxLogSize);

            $currentComments = $lead['result']['item']['comments'] ?? '';
            $paymentMarker = 'ID счёта: ' . ($data['invoice_id'] ?? 'N/A');

            if (strpos($currentComments, $paymentMarker) !== false) {
                writeLog($logFile, 'PAYMENT ALREADY RECORDED (invoice_id=' . ($data['invoice_id'] ?? '') . ')', $maxLogSize);
                break;
            }

            $paymentInfo = "\n\n=== ПОСТУПИЛ ОНЛАЙН-ПЛАТЁЖ ===\n"
                . "Дата: " . ($data['date'] ?? '') . "\n"
                . "Сумма: " . ($data['price'] ?? 0) . "\n"
                . "Платёжный сервис: " . ($data['pay_service'] ?? 'N/A') . "\n"
                . $paymentMarker;

            if (!$DRY_RUN) {
                $result = callBitrix('crm.item.update', [
                    'entityTypeId' => $leadEntityTypeId,
                    'id' => $leadId,
                    'fields' => [
                        'comments' => $currentComments . $paymentInfo,
                        'opportunity' => $data['price'] ?? 0,
                        'currencyId' => 'RUB'
                    ]
                ], $bitrixWebhookUrl, $logFile, $maxLogSize);
            } else {
                writeLog($logFile, 'DRY RUN: would add payment to lead #' . $leadId, $maxLogSize);
            }
        }
        break;

    // --------------------------------------------------------
    // НЕИЗВЕСТНОЕ СОБЫТИЕ
    // --------------------------------------------------------
    default:
        writeLog($logFile, 'UNHANDLED EVENT: ' . $eventType, $maxLogSize);
        break;
}

// ============================================================
// 5. СОХРАНЯЕМ СОБЫТИЕ В ЛОГ ОБРАБОТАННЫХ
// ============================================================

markEventProcessed($processedFile, $eventHash, $maxLogSize);

// ============================================================
// 6. ОТВЕЧАЕМ LitePMS
// ============================================================

http_response_code(200);
echo 'OK';
exit;


// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

function writeLog($logFile, $message, $maxSize) {
    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice($lines, -1000);
        file_put_contents($logFile, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    }

    file_put_contents(
        $logFile,
        date('Y-m-d H:i:s') . ' | ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function isEventProcessed($processedFile, $eventHash) {
    if (!file_exists($processedFile)) {
        return false;
    }

    $events = file($processedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return in_array($eventHash, $events);
}

function markEventProcessed($processedFile, $eventHash, $maxSize) {
    file_put_contents($processedFile, $eventHash . PHP_EOL, FILE_APPEND | LOCK_EX);

    if (file_exists($processedFile) && filesize($processedFile) > $maxSize) {
        $events = file($processedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $events = array_slice($events, -5000);
        file_put_contents($processedFile, implode(PHP_EOL, $events) . PHP_EOL, LOCK_EX);
    }
}

function callBitrix($method, $params, $bitrixWebhookUrl, $logFile, $maxSize) {
    $url = $bitrixWebhookUrl . $method . '.json';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    writeLog($logFile, 'OUT [' . $method . '] (' . $httpCode . '): ' . $response, $maxSize);

    return json_decode($response, true);
}

function findExistingLead($bookingId, $userBookingId, $entityTypeId, $bookingIdField, $userBookingIdField, $bitrixWebhookUrl, $logFile, $maxSize) {
    $filters = [];

    if (!empty($bookingId)) {
        $filters[] = [$bookingIdField => $bookingId];
    }
    if (!empty($userBookingId)) {
        $filters[] = [$userBookingIdField => $userBookingId];
    }

    if (empty($filters)) {
        return null;
    }

    foreach ($filters as $filter) {
        $result = callBitrix('crm.item.list', [
            'entityTypeId' => $entityTypeId,
            'filter' => $filter,
            'select' => ['id', 'title']
        ], $bitrixWebhookUrl, $logFile, $maxSize);

        if (!empty($result['result']['items'][0]['id'])) {
            return $result['result']['items'][0]['id'];
        }
    }

    return null;
}

function buildLeadFields($data, $bookingIdField, $userBookingIdField, $roistatField, $yclidField, $ymClidField, $etextField) {
    // Заголовок
    $title = 'Бронирование LitePMS';
    $clientName = trim(($data['client_surname'] ?? '') . ' ' . ($data['client_name'] ?? ''));
    if (!empty($clientName)) {
        $title = 'Бронь: ' . $clientName;
    } elseif (!empty($data['name'])) {
        $title = 'Заявка: ' . $data['name'];
    }

    // Комментарий
    $comments = [];
    $comments[] = '=== Данные из LitePMS ===';
    $comments[] = 'Тип события: ' . ($data['type'] ?? 'N/A');
    $comments[] = 'ID брони: ' . ($data['booking_id'] ?? 'N/A');
    $comments[] = 'Номер брони: ' . ($data['user_booking_id'] ?? 'N/A');

    if (!empty($data['date_in']) || !empty($data['date_out'])) {
        $comments[] = 'Даты: ' . ($data['date_in'] ?? '?') . ' — ' . ($data['date_out'] ?? '?');
    }
    if (!empty($data['time_in']) || !empty($data['time_out'])) {
        $comments[] = 'Время: ' . ($data['time_in'] ?? '?') . ' — ' . ($data['time_out'] ?? '?');
    }

    $comments[] = 'Номер: ' . ($data['room_name'] ?? 'N/A');
    $comments[] = 'Категория: ' . ($data['cat_name'] ?? 'N/A');
    $comments[] = 'Гостей: ' . ($data['person'] ?? 0) . ' осн. + '
        . ($data['person_add'] ?? 0) . ' доп. + ' . ($data['children'] ?? 0) . ' дет.';

    if (!empty($data['price'])) {
        $comments[] = 'Стоимость: ' . $data['price'];
    }
    if (!empty($data['stayprice'])) {
        $comments[] = 'Стоимость проживания: ' . $data['stayprice'];
    }

    $statuses = [
        1 => 'Не подтверждено', 2 => 'Подтверждено', 3 => 'Отменено',
        4 => 'Выезд', 5 => 'Незаезд', 6 => 'Проживание',
        8 => 'Резерв', 9 => 'Overbooking'
    ];
    if (!empty($data['status_id']) && isset($statuses[$data['status_id']])) {
        $comments[] = 'Статус: ' . $statuses[$data['status_id']];
    }

    if (!empty($data['comment'])) {
        $comments[] = 'Комментарий: ' . $data['comment'];
    }
    if (!empty($data['client_booking_comment'])) {
        $comments[] = 'Комментарий клиента: ' . $data['client_booking_comment'];
    }

    // UTM-метки (для справки в комментарии)
    if (!empty($data['utm_data']) && is_array($data['utm_data'])) {
        $utmParts = [];
        foreach ($data['utm_data'] as $k => $v) {
            if (!empty($v)) $utmParts[] = $k . '=' . $v;
        }
        if (!empty($utmParts)) {
            $comments[] = 'UTM: ' . implode(' | ', $utmParts);
        }
    }

    if (!empty($data['yclid']))   $comments[] = 'yclid: ' . $data['yclid'];
    if (!empty($data['etext']))   $comments[] = 'etext: ' . $data['etext'];
    if (!empty($data['ym_clid']) && $data['ym_clid'] != '0') {
        $comments[] = 'Яндекс ClientID: ' . $data['ym_clid'];
    }

    // Базовые поля
    $fields = [
        'title' => $title,
        'comments' => implode("\n", $comments),
        'sourceId' => 'LITEPMS',
        'sourceDescription' => 'Интеграция с LitePMS',
        $bookingIdField => $data['booking_id'] ?? null,
        $userBookingIdField => $data['user_booking_id'] ?? null,
    ];

    // Имя, фамилия, отчество
    if (!empty($data['client_name']))       $fields['name'] = $data['client_name'];
    if (!empty($data['client_surname']))    $fields['lastName'] = $data['client_surname'];
    if (!empty($data['client_middlename'])) $fields['secondName'] = $data['client_middlename'];

    // Мультиполя
    $fm = [];
    if (!empty($data['client_phone'])) {
        $fm[] = ['typeId' => 'PHONE', 'valueType' => 'WORK', 'value' => $data['client_phone']];
    }
    if (!empty($data['client_email'])) {
        $fm[] = ['typeId' => 'EMAIL', 'valueType' => 'WORK', 'value' => $data['client_email']];
    }
    if (!empty($data['client_address'])) {
        $fm[] = ['typeId' => 'ADDRESS', 'valueType' => 'WORK', 'value' => $data['client_address']];
    }
    if (!empty($fm)) {
        $fields['fm'] = $fm;
    }

    if (!empty($data['client_birthday']) && $data['client_birthday'] != '0000-00-00') {
        $fields['birthdate'] = $data['client_birthday'];
    }

    // ============================================================
    // UTM-МЕТКИ из utm_data[] — в стандартные поля лида
    // ============================================================
    if (!empty($data['utm_data']) && is_array($data['utm_data'])) {
        $utm = $data['utm_data'];
        if (!empty($utm['utm_source']))   $fields['utmSource'] = $utm['utm_source'];
        if (!empty($utm['utm_medium']))   $fields['utmMedium'] = $utm['utm_medium'];
        if (!empty($utm['utm_campaign'])) $fields['utmCampaign'] = $utm['utm_campaign'];
        if (!empty($utm['utm_content']))  $fields['utmContent'] = $utm['utm_content'];
        if (!empty($utm['utm_term']))     $fields['utmTerm'] = $utm['utm_term'];
    }

    // ============================================================
    // ROISTAT и рекламные параметры — в пользовательские поля
    // ============================================================
    if (!empty($data['roistat'])) {
        $fields[$roistatField] = $data['roistat'];
    }
    if (!empty($data['yclid'])) {
        $fields[$yclidField] = $data['yclid'];
    }
    if (!empty($data['ym_clid']) && $data['ym_clid'] != '0') {
        $fields[$ymClidField] = $data['ym_clid'];
    }
    if (!empty($data['etext'])) {
        $fields[$etextField] = $data['etext'];
    }

    return $fields;
}
