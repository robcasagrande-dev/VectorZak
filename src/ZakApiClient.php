<?php
namespace VectorZak;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class ZakApiClient {
    private $client;
    private $apiKey;
    private $lcode;
    
    // Caches to speed up bulk processing
    private $cachedRooms = null;
    private $cachedReservations = [];
    private $cachedCustomers = [];
    private $cachedExtras = [];

    public function __construct() {
        $this->apiKey = ZAK_API_KEY;
        $this->lcode = ZAK_LCODE;
        
        if (class_exists('GuzzleHttp\Client')) {
            $this->client = new Client([
                'base_uri' => ZAK_API_URL,
                'timeout'  => 15.0,
                'headers'  => [
                    'x-api-key'   => $this->apiKey,
                    'Lcode'       => $this->lcode,
                    'Accept'      => 'application/json'
                ]
            ]);
        }
    }
    
    public function findActiveReservation($roomNumber, $date, $time) {
        if (!$this->client) {
            return ['id' => rand(1000, 9999), 'notes' => 'Existing notes.'];
        }
        
        $cacheKey = "{$roomNumber}_{$date}";
        if (isset($this->cachedReservations[$cacheKey])) {
            return $this->cachedReservations[$cacheKey];
        }
        
        $debugLog = "--- DEBUG LOG PARA HABITACIÓN $roomNumber EN FECHA $date ---\n";
        
        try {
            // 1. Fetch properties to map the string "4" to internal id_zak_room
            if ($this->cachedRooms === null) {
                $roomResponse = $this->client->post("property/fetch_rooms", ['json' => []]);
                $roomData = json_decode($roomResponse->getBody(), true);
                $this->cachedRooms = $roomData['data'] ?? [];
            }
            $allRooms = $this->cachedRooms;
            
            $targetRoomIds = [];
            foreach ($allRooms as $r) {
                if (preg_match("/\b" . preg_quote((string)$roomNumber, '/') . "\b/", $r['name'])) {
                    $targetRoomIds[] = $r['id'];
                }
            }
            
            $debugLog .= "Room Mapping: Encontrados " . count($targetRoomIds) . " IDs internos para '$roomNumber': " . implode(', ', $targetRoomIds) . "\n";
            
            // Convert YYYY-MM-DD to DD/MM/YYYY for Wubook
            $dParts = explode('-', $date);
            if (count($dParts) === 3) {
                $wubookDate = $dParts[2] . '/' . $dParts[1] . '/' . $dParts[0];
                
                // Narrow the search window to 20 days prior to avoid hitting the ZaK 8-result pagination limit
                $prevWeek = date('Y-m-d', strtotime("$date -20 days"));
                $pParts = explode('-', $prevWeek);
                $firstDay = $pParts[2] . '/' . $pParts[1] . '/' . $pParts[0]; 
                $lastDay = $dParts[2] . '/' . $dParts[1] . '/' . $dParts[0];
            } else {
                $wubookDate = $date; $firstDay = $date; $lastDay = $date;
            }
            
            $debugLog .= "Búsqueda: Rango de llegada $firstDay al $lastDay\n";
            
            $targetTime = strtotime($date); // POS Invoice Date
            $limit = 8;
            $offset = 0;
            $page = 1;
            
            while (true) {
                $debugLog .= "\nBuscando Página $page (Offset: $offset)...\n";
                
                // 2. Fetch reservations - ZaK API REQUIRES form-encoded filters!
                $response = $this->client->post("reservations/fetch_reservations", [
                    'form_params' => [
                        'filters' => json_encode([
                            'arrival' => ['from' => $firstDay, 'to' => $lastDay],
                            'pager' => ['limit' => $limit, 'offset' => $offset]
                        ])
                    ]
                ]);
                
                $data = json_decode($response->getBody(), true);
                $reservations = $data['data']['reservations'] ?? [];
                
                $debugLog .= "API devolvió " . count($reservations) . " reservas en la Página $page.\n";
                
                if (count($reservations) === 0) {
                    $debugLog .= "No hay más reservas. Fin de la búsqueda.\n";
                    break;
                }
                
                // 3. Match room ID and date overlap
                foreach ($reservations as $res) {
                    foreach ($res['rooms'] ?? [] as $room) {
                        if (in_array($room['id_zak_room'], $targetRoomIds)) {
                            // Convert DD/MM/YYYY to timestamp
                            $dfromParts = explode('/', $room['dfrom']);
                            $dtoParts = explode('/', $room['dto']);
                            $dfrom = strtotime($dfromParts[2] . '-' . $dfromParts[1] . '-' . $dfromParts[0]);
                            $dto = strtotime($dtoParts[2] . '-' . $dtoParts[1] . '-' . $dtoParts[0]);
                            
                            $debugLog .= "  -> Verificando Reserva ID {$res['id']}: Hab. interna {$room['id_zak_room']}, Desde {$room['dfrom']} Hasta {$room['dto']}\n";
                            $debugLog .= "  -> RAW DATA: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
                            
                            if ($targetTime >= $dfrom && $targetTime <= $dto) {
                                $isValidMatch = true;
                                
                                if ($targetTime == $dfrom || $targetTime == $dto) {
                                    $invoiceTimeStr = trim($time);
                                    if (!empty($invoiceTimeStr)) {
                                        $timeParts = explode(':', $invoiceTimeStr);
                                        $invoiceHour = (int)($timeParts[0] ?? 0);
                                        $invoiceMinute = (int)($timeParts[1] ?? 0);
                                        $invoiceDecimalTime = $invoiceHour + ($invoiceMinute / 60);
                                        $checkinThreshold = 13.0; // 13:00
                                        $checkoutThreshold = 14.0; // 14:00
                                        
                                        if ($targetTime == $dfrom && $targetTime == $dto) {
                                            $isValidMatch = true; // In and out same day
                                        } elseif ($targetTime == $dfrom && $invoiceDecimalTime < $checkinThreshold) {
                                            $isValidMatch = false;
                                            $debugLog .= "  -> IGNORADO: Es el día de Check-in ({$room['dfrom']}) pero la hora del comprobante ({$invoiceTimeStr}) es antes de las 13:00.\n";
                                        } elseif ($targetTime == $dto && $invoiceDecimalTime >= $checkoutThreshold) {
                                            $isValidMatch = false;
                                            $debugLog .= "  -> IGNORADO: Es el día de Checkout ({$room['dto']}) pero la hora del comprobante ({$invoiceTimeStr}) es después de las 14:00.\n";
                                        }
                                    }
                                }
                                
                                // Check if room is actually checked in (has checkin date and no checkout date)
                                if ($isValidMatch) {
                                    $isCheckedIn = false;
                                    $customers = $room['customers'] ?? [];
                                    foreach ($customers as $cust) {
                                        if (!empty($cust['checkin']) && empty($cust['checkout'])) {
                                            $isCheckedIn = true;
                                            break;
                                        }
                                    }
                                    
                                    if (!$isCheckedIn) {
                                        $isValidMatch = false;
                                        $debugLog .= "  -> IGNORADO: La habitación no está actualmente en estadía (sin check-in o ya hizo check-out).\n";
                                    }
                                }
                                
                                if ($isValidMatch) {
                                    $debugLog .= "  -> MATCH EXITOSO en Página $page!\n";
                                    
                                    // Lookup real ZaK room name
                                    $zakRoomName = "Desconocida";
                                    foreach ($allRooms as $r) {
                                        if ($r['id'] == $room['id_zak_room']) {
                                            $zakRoomName = $r['name'];
                                            break;
                                        }
                                    }
                                    
                                    // Fetch real guest name
                                    $bookerId = $res['booker'] ?? null;
                                    $guestName = $this->fetchCustomerName($bookerId);
                                    
                                    $result = [
                                        'id' => $res['id'],
                                        'id_human' => $res['id_human'] ?? 'Desconocido',
                                        'notes' => $res['notes'] ?? '',
                                        'zak_room_name' => $zakRoomName,
                                        'guest_name' => $guestName,
                                        'debug' => $debugLog
                                    ];
                                    $this->cachedReservations[$cacheKey] = $result;
                                    return $result;
                                }
                            } else {
                                $debugLog .= "  -> FALLO: Fecha de comprobante ($wubookDate) fuera de rango.\n";
                            }
                        }
                    }
                }
                
                // Check if we reached the last page
                if (count($reservations) < $limit) {
                    break;
                }
                
                $offset += $limit;
                $page++;
            }
            
            $debugLog .= "RESULTADO: No se encontró superposición de fechas válida.\n";
            $result = ['debug' => $debugLog];
            $this->cachedReservations[$cacheKey] = $result;
            return $result;
            
        } catch (RequestException $e) {
            $msg = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            $debugLog .= "EXCEPCIÓN API: " . $msg;
            $result = ['api_error' => $msg, 'debug' => $debugLog];
            $this->cachedReservations[$cacheKey] = $result;
            return $result;
        }
    }

    /**
     * Fetch a specific customer's details by their ID to get their name
     */
    public function fetchCustomerName($customerId) {
        if (!$customerId) return "Huésped Desconocido";
        if (isset($this->cachedCustomers[$customerId])) {
            return $this->cachedCustomers[$customerId];
        }
        
        try {
            $resp = $this->client->post("customers/fetch_one", [
                'form_params' => ['id' => $customerId]
            ]);
            
            $data = json_decode($resp->getBody(), true);
            if (isset($data['data']['main_info']) && is_array($data['data']['main_info'])) {
                $name = $data['data']['main_info']['name'] ?? '';
                $lastName = $data['data']['main_info']['surname'] ?? '';
                $fullName = trim($name . ' ' . $lastName);
                $finalName = $fullName !== '' ? $fullName : "Huésped Desconocido";
                $this->cachedCustomers[$customerId] = $finalName;
                return $finalName;
            }
        } catch (\Exception $e) {
            // Silently fail and return unknown if customer fetch fails
        }
        
        return "Huésped Desconocido";
    }

    /**
     * Appends a Note (now an Extra) to a specific reservation
     */
    public function appendNoteToReservation($reservation, $invoiceRow) {
        $reservationId = $reservation['id'];
        $invoiceNumber = $invoiceRow['invoice'];
        
        $debugLog = "Procesando Comprobante #$invoiceNumber para Reserva $reservationId...\n";
        
        // 1. Fetch existing extras to check for duplicates
        try {
            if (!isset($this->cachedExtras[$reservationId])) {
                $extrasResp = $this->client->get("reservations/get_extras", [
                    'query' => ['rsrvid' => $reservationId]
                ]);
                $extrasData = json_decode($extrasResp->getBody(), true);
                $this->cachedExtras[$reservationId] = $extrasData['data'] ?? [];
            }
            $existingExtras = $this->cachedExtras[$reservationId];
            
            // Check if this invoice is already injected
            foreach ($existingExtras as $ex) {
                // We assume the invoice number is stored in the name of the extra
                $extraName = $ex['extra_info']['name'] ?? '';
                if (strpos($extraName, "Comprobante #$invoiceNumber") !== false) {
                    return ['status' => 'skipped', 'message' => "Comprobante #$invoiceNumber OMITIDO: Ya existe un Extra cargado con este número de comprobante en la reserva."];
                }
            }
        } catch (\Exception $e) {
            $debugLog .= "Error al verificar duplicados: " . $e->getMessage() . "\n";
        }
        
        // 2. Add the Extra (0 COP for testing)
        // Format date to DD/MM/YYYY for Zak API
        $dateParts = explode('-', $invoiceRow['date']);
        $wubookDate = $dateParts[2] . '/' . $dateParts[1] . '/' . $dateParts[0];
        $waiter = $invoiceRow['waiter'] ?? 'Desconocido';
        $time = $invoiceRow['time'] ?? '';
        
        try {
            $roundedPrice = floor($invoiceRow['amount'] / 1000) * 1000;
            $extraObj = [
                'price' => $roundedPrice,
                'name' => "Restaurante - Comprobante #{$invoiceNumber} | Hora: {$time} | Mesa: {$invoiceRow['table']} | Mesero: {$waiter}",
                'number' => 1,
                'daily' => "false",
                'day' => $wubookDate,
                'vat_rate' => 8,
                'currency' => "COP"
            ];
            
            $addResp = $this->client->post("reservations/add_extra", [
                'form_params' => [
                    'rsrvid' => $reservationId,
                    'extra' => json_encode($extraObj)
                ]
            ]);
            
            $addData = json_decode($addResp->getBody(), true);
            
            if (isset($addData['error'])) {
                return ['status' => 'error', 'message' => "Error al agregar Extra para Comprobante #$invoiceNumber: " . $addData['error']];
            }
            
            // Get the guest's real name for the success message
            $guestName = $reservation['guest_name'] ?? "Huésped Desconocido";
            $humanId = $reservation['id_human'] ?? $reservationId;
            
            // Update cache so subsequent duplicates in the same run are skipped
            $this->cachedExtras[$reservationId][] = [
                'extra_info' => ['name' => $extraObj['name']]
            ];
            
            return [
                'status' => 'success',
                'message' => "Cargo Extra inyectado exitosamente a la Reserva $humanId ($guestName) para el Comprobante #$invoiceNumber."
            ];
            
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => "Excepción al agregar Extra para Comprobante #$invoiceNumber: " . $e->getMessage()];
        }
    }

    /**
     * Fetch reservation details by its human-readable reservation code (rcode).
     */
    public function fetchReservationByCode($rcode) {
        if (!$this->client) {
            // Mock data for local testing
            return [
                'id' => 27851768,
                'id_human' => $rcode,
                'dfrom' => '01/06/2026',
                'dto' => '04/06/2026',
                'guest_name' => 'Alexander Rogstad Rustand'
            ];
        }
        
        $response = $this->client->post("reservations/fetch_one_reservation", [
            'form_params' => [
                'rcode' => $rcode
            ]
        ]);
        
        $data = json_decode($response->getBody(), true);
        if (isset($data['error'])) {
            throw new \Exception($data['error']);
        }
        
        $res = $data['data'] ?? null;
        if (!$res) {
            throw new \Exception("Reserva no encontrada en ZaK.");
        }
        
        $rooms = $res['rooms'] ?? [];
        if (count($rooms) === 0) {
            throw new \Exception("La reserva no tiene habitaciones asociadas.");
        }
        
        // Fetch real guest name
        $bookerId = $res['booker'] ?? null;
        $guestName = $this->fetchCustomerName($bookerId);
        
        return [
            'id' => $res['id'],
            'id_human' => $res['id_human'],
            'dfrom' => $rooms[0]['dfrom'],
            'dto' => $rooms[0]['dto'],
            'guest_name' => $guestName
        ];
    }
}

