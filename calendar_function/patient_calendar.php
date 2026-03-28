<?php
// File: patient_calendar.php

// Ensure session is started before using $_SESSION
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once '../calendar_function/calendar_conn.php';

// --- START: Data Fetching and Duration Parsing ---

// --- IMPORTANT: Use your actual session variable here ---
$user_id = $_SESSION['user_id'] ?? 0;

if ($user_id == 0) {
    // In a live environment, you might redirect here:
    // header('Location: ../public/login.php');
    die("Access denied. Please log in to book an appointment.");
}

$patientDetails = [];
$services = [];
$jsServiceDurations = []; // Array to pass service_id => duration_minutes to JavaScript

try {
    // 1. Fetch Patient Details
    $stmt = $pdo->prepare("
        SELECT 
            u.user_id, 
            p.fullname AS full_name, 
            p.email, 
            p.contact_number, 
            p.gender
        FROM users u
        INNER JOIN patient p ON u.user_id = p.user_id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $patientDetails = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patientDetails) {
        die("Error: Could not retrieve patient details for user ID {$user_id}. Ensure this user has an entry in the 'patient' table.");
    }

    // 2. Fetch all available services and calculate duration in minutes
    $servicesStmt = $pdo->query("
        SELECT service_id, service_name, duration, price
        FROM services 
        WHERE status = 'Active'
        ORDER BY service_name
    ");
    $services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($services as $service) {
        $durationText = $service['duration'];
        $minutes = 60; // Default to 60 minutes (1 hour) if parsing fails
        
        if (preg_match('/(\d+)\s*minutes?/i', $durationText, $m)) {
            $minutes = (int)$m[1];
        } elseif (preg_match('/(\d+)\s*hours?/i', $durationText, $m)) {
            $minutes = (int)$m[1] * 60;
        } elseif (is_numeric($durationText)) {
            $minutes = (int)$durationText;
        }
        
        $jsServiceDurations[(int)$service['service_id']] = $minutes;
    }

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// --- END: Data Fetching and Duration Parsing ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>DentiTrack | Patient Calendar</title>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">


<style>
/* -------------------------------------------
    GENERAL STYLES
------------------------------------------- */
:root {
    --primary-color: #0d6efd;
    --success-color: #32CD32;
    --error-color: #dc3545;
    --loading-spinner-color: var(--primary-color);
}

.calendar-title {
    text-align: center;
    color: #333;
    margin-bottom: 10px;
}
.legend {
    display: flex;
    justify-content: center;
    gap: 15px;
    margin-bottom: 15px;
}
.legend-item { display: flex; align-items: center; gap: 6px; }
.color-box { width: 18px; height: 18px; border-radius: 3px; }

#calendar {
    max-width: 900px;
    margin: 0 auto;
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

/* Calendar search bar */
#calendarSearch {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-bottom: 15px;
}
#calendarSearch select, #calendarSearch input {
    padding: 6px;
    border-radius: 6px;
    border: 1px solid #ccc;
}
#calendarSearch button {
    background-color: var(--primary-color);
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
}
#calendarSearch button:hover {
    background-color: #0b5ed7;
}

/* -------------------------------------------
    CALENDAR EVENT STATUS CLASSES
------------------------------------------- */
.status-booked { background-color: #4A90E2 !important; color: white !important; }
.status-completed { background-color: var(--success-color) !important; color: black !important; }

/* Dark Green color for Fully Booked/Rest Day */
.status-fully_booked { background-color: #008000 !important; color: white !important; }
.status-restday { background-color: #008000 !important; color: white !important; }

.fc-day-today {
    background-color: #e0e0e0 !important;
    color: #000 !important;
    font-weight: 600;
    border: 2px solid #b0b0b0 !important;
    border-radius: 6px;
}

/* Modal layout: left = form, right = timeslots */
.modal-body {
    display: flex;
    gap: 18px;
}
.modal-body .form-column { flex: 2; }
.modal-body .slots-column { flex: 1; border-left: 1px solid #eee; padding-left: 12px; }

/* -------------------------------------------
    TIMESLOT VISUALS (in the modal)
------------------------------------------- */
.slot {
    padding: 8px 10px;
    margin-bottom: 8px;
    border-radius: 8px;
    text-align: center;
    font-weight: 600;
}

#slotList {
    max-height: 400px; 
    overflow-y: auto;  
    padding-right: 10px; 
}

.slot.available { background: #f1f1f1; color: #111; cursor: pointer; }
.slot.booked { background: #CCE5FF; color: #004085; }
.slot.completed { background: #D4EDDA; color: #155724; }
.slot.restday { background: #8FD19E; color: #000; } 
.slot.lunchbreak { background: #FFDAB9; color: #694F1A; } 
.slot.selected { outline: 2px solid var(--primary-color); }

/* hide slots panel when not populated */
.slots-empty { color: #888; text-align:center; padding: 20px 10px; }

/* Payment Section Styles */
.payment-section { border: 1px solid var(--primary-color); padding: 15px; border-radius: 8px; background: #f7faff; }
.payment-section h5 { margin-top: 0; }

@media (max-width: 900px) {
    .modal-body { flex-direction: column; }
    .modal-dialog { max-width: 95%; }
}

/* -------------------------------------------
    LOADING SPINNER OVERLAY
------------------------------------------- */
#loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.85); /* Light semi-transparent background */
    display: none; /* Hidden by default */
    justify-content: center;
    align-items: center;
    z-index: 9999; /* Ensure it's on top of everything */
    flex-direction: column;
}
.spinner-content {
    background: white;
    padding: 30px 40px;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
    text-align: center;
    color: #343a40; /* text-dark equivalent */
    font-size: 1.1rem;
    font-weight: 600;
}
.app-spinner {
    border: 4px solid #f3f3f3; /* Light grey */
    border-top: 4px solid var(--loading-spinner-color); /* Primary Blue */
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin-bottom: 15px;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>
</head>
<body>

<h2 class="calendar-title">🦷 Patient Appointment Calendar</h2>

<div id="calendarSearch">
    <select id="searchYear"></select>
    <select id="searchMonth">
        <option value="">Month</option>
        <option value="0">January</option>
        <option value="1">February</option>
        <option value="2">March</option>
        <option value="3">April</option>
        <option value="4">May</option>
        <option value="5">June</option>
        <option value="6">July</option>
        <option value="7">August</option>
        <option value="8">September</option>
        <option value="9">October</option>
        <option value="10">November</option>
        <option value="11">December</option>
    </select>
    <input type="number" id="searchDay" placeholder="Day" min="1" max="31" style="width:80px;">
    <button id="goToDate">Go</button>
</div>

<div class="legend">
    <div class="legend-item"><div class="color-box" style="background:#4A90E2;"></div> Booked</div>
    <div class="legend-item"><div class="color-box" style="background:#32CD32;"></div> Completed</div>
    <div class="legend-item"><div class="color-box" style="background:#008000;"></div> Rest Day / Fully Booked</div>
</div>

<div id="calendar"></div>

<div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="appointmentModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="appointmentForm" enctype="multipart/form-data" method="POST"> 
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="appointmentModalLabel">Book Appointment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="form-column">
                        
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($patientDetails['user_id']); ?>">
                        
                        <div class="mb-2">
                            <label class="form-label">Patient:</label>
                            <p class="form-control-static fw-bold mb-0">
                                <?php echo htmlspecialchars($patientDetails['full_name']); ?> 
                                (<?php echo htmlspecialchars($patientDetails['email']); ?> | 
                                <?php echo htmlspecialchars($patientDetails['contact_number']); ?> | 
                                <?php echo htmlspecialchars($patientDetails['gender']); ?>)
                            </p>
                        </div>
                        
                        <div class="mb-2">
                            <label class="form-label">Service</label>
                            <select name="service_id" id="service_id_select" class="form-select" required onchange="togglePaymentForms()">
                                <option value="">Select Service (Duration - Price)</option>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?php echo htmlspecialchars($service['service_id']); ?>" data-price="<?php echo htmlspecialchars($service['price']); ?>">
                                        <?php echo htmlspecialchars($service['service_name']); ?> (<?php echo htmlspecialchars($service['duration']); ?> - ₱<?php echo htmlspecialchars(number_format($service['price'], 2)); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-2"><label class="form-label">Appointment Date</label><input type="date" name="appointment_date" id="appointment_date" class="form-control" readonly required></div>
                        
                        <div class="mb-2"><label class="form-label">Appointment Time</label><select name="appointment_time" id="appointment_time" class="form-select" required></select></div>
                        
                        <hr>
                        
                        <div class="mb-2 text-end">
                            <label class="form-label fw-bold text-primary">Total Service Price:</label>
                            <span id="final_price_display" class="fw-bold text-primary fs-5">₱0.00</span>
                            </div>
                        <hr>
                        
                        <div class="mb-3 payment-section">
                            <h5 class="text-primary text-center">Payment Options (Downpayment Required)</h5>
                            
                            <div class="d-flex justify-content-around mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="primary_method" id="walkInRadio" value="Walk-in" onchange="togglePaymentForms()" checked>
                                    <label class="form-check-label fw-bold" for="walkInRadio">Walk-in Downpayment</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="primary_method" id="onlineRadio" value="Online" onchange="togglePaymentForms()">
                                    <label class="form-check-label fw-bold" for="onlineRadio">Online Downpayment</label>
                                </div>
                            </div>
                            
                            <div id="walk-in-form-group">
                                <div class="mb-2 text-center alert alert-info py-2">
                                    <p class="mb-1 fw-bold">Online Payment:</p>
                                    <p class="mb-1 fs-5">Gcash: 09913637693</p>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="showGcashQrCodeDisplay()">
                                        <i class="fas fa-qrcode"></i> Show Gcash QR Code
                                    </button>
                                    <p class="mb-1 fs-5">Bank Account No: 9108-2025-0012-3456</p>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="showBankQrCodeDisplay()">
                                        <i class="fas fa-qrcode"></i> Show Bank QR Code
                                    </button>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="downpayment_walkin">Downpayment Amount (500₱ Minimum amount must be paid for booking)</label>
                                    <input type="number" name="amount_paid" id="downpayment_walkin" class="form-control" step="0.01" min="0" value="0.00" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Image Proof of Downpayment</label>
                                    <input type="file" name="payment_proof" id="proof_walkin" class="form-control" accept="image/*" required>
                                </div>
                            </div>
                            
                            <div id="online-form-group" style="display: none;">
                                
                                <div class="mb-2 text-center alert alert-info py-2">
                                    <p class="mb-1 fw-bold">Send Downpayment to:</p>
                                    <p class="mb-1 fs-5">Gcash: 09913637693</p>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="showGcashQrCodeDisplay()">
                                        <i class="fas fa-qrcode"></i> Show Gcash QR Code
                                    </button>
                                    <p class="mb-1 fs-5">Bank Account No: 9108-2025-0012-3456</p>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="showBankQrCodeDisplay()">
                                        <i class="fas fa-qrcode"></i> Show Bank QR Code
                                    </button>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label">Downpayment Amount (500₱ Minimum amount must be paid for booking)</label>
                                    <input type="number" name="amount_paid_online_hidden" id="downpayment_online" class="form-control" step="0.01" min="0" value="0.00" required> 
                                </div>
                                
                                <div class="mb-2">
                                    <label class="form-label" id="online_proof_label">Image Proof of Downpayment</label>
                                    <input type="file" name="payment_proof_online" id="proof_online" class="form-control" accept="image/*" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-2"><label class="form-label">Comments</label><textarea name="comments" class="form-control" rows="2" placeholder="Optional..."></textarea></div>
                    </div>
                    
                    <div class="slots-column">
                        <h6 id="slotsForDateTitle" class="mb-2 text-center">Select a date to view slots</h6>
                        <div id="slotList" class="slots-empty">No date selected</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary w-100">Submit Appointment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="messageModal" tabindex="-1" aria-labelledby="messageModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="messageModalLabel">Appointment Status</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div id="modalMessageContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div id="inlineGcashQrDisplay" class="border rounded shadow p-3 bg-white" 
      style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); z-index:2000; width: 300px;">
    <div class="d-flex justify-content-between align-items-center mb-2 bg-primary text-white p-2 m-n3 rounded-top">
        <h5 class="m-0 text-white fs-6">Gcash Payment QR Code</h5>
        <button type="button" class="btn-close btn-close-white" onclick="hideGcashQrCodeDisplay()" aria-label="Close"></button>
    </div>
    <div class="text-center mt-3">
        <img src="../uploads/online_payment/gcash-qr.png" alt="Online Payment QR Code" class="img-fluid border rounded" style="max-height: 250px;">
        <p class="mt-2 mb-0 fw-bold">Gcash: 09913637693</p>
    </div>
</div>

<div id="inlineBankQrDisplay" class="border rounded shadow p-3 bg-white" 
      style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); z-index:2000; width: 300px;">
    <div class="d-flex justify-content-between align-items-center mb-2 bg-primary text-white p-2 m-n3 rounded-top">
        <h5 class="m-0 text-white fs-6">Bank Payment QR Code</h5>
        <button type="button" class="btn-close btn-close-white" onclick="hideBankQrCodeDisplay()" aria-label="Close"></button>
    </div>
    <div class="text-center mt-3">
        <img src="../uploads/online_payment/bank-qr.png" alt="Bank Payment QR Code" class="img-fluid border rounded" style="max-height: 250px;">
        <p class="mt-2 mb-0 fw-bold">Account No: 9108-2025-0012-3456</p>
    </div>
</div>

<div id="loading-overlay">
    <div class="spinner-content">
        <div class="app-spinner"></div>
        Processing Request...
        <p class="text-secondary mb-0 mt-2" style="font-size: 0.8rem;">Please wait, verifying time slots and submitting payment proof.</p>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// --- LOADING SPINNER UTILITIES ---
function showLoading(message = 'Processing Request...') {
    document.querySelector('#loading-overlay .spinner-content').innerHTML = `
        <div class="app-spinner"></div>
        ${message}
        <p class="text-secondary mb-0 mt-2" style="font-size: 0.8rem;">Please wait, verifying time slots and submitting payment proof.</p>
    `;
    document.getElementById('loading-overlay').style.display = 'flex';
}
function hideLoading() {
    document.getElementById('loading-overlay').style.display = 'none';
}
// --- END LOADING SPINNER UTILITIES ---


document.addEventListener('DOMContentLoaded', function () {
    const calendarEl = document.getElementById('calendar');
    const modalEl = document.getElementById('appointmentModal');
    // We already initialize a bootstrap.Modal instance for the Appointment Modal
    const modal = new bootstrap.Modal(modalEl); 
    const slotListEl = document.getElementById('slotList');
    const appointmentDateInput = document.getElementById('appointment_date');
    const appointmentTimeSelect = document.getElementById('appointment_time');
    const slotsForDateTitle = document.getElementById('slotsForDateTitle');
    const appointmentForm = document.getElementById('appointmentForm');
    
    const serviceSelect = document.getElementById('service_id_select');
    
    // QR Code Display elements
    const gcashQrDisplayEl = document.getElementById('inlineGcashQrDisplay');
    const bankQrDisplayEl = document.getElementById('inlineBankQrDisplay');
    
    // Message Modal elements
    const messageModalEl = document.getElementById('messageModal'); 
    const messageModal = new bootstrap.Modal(messageModalEl);
    const modalMessageContent = document.getElementById('modalMessageContent');
    const messageModalLabel = document.getElementById('messageModalLabel');
    
    // Payment/Price Elements
    const walkInGroup = document.getElementById('walk-in-form-group');
    const onlineGroup = document.getElementById('online-form-group');
    const onlineProofLabel = document.getElementById('online_proof_label');
    const finalPriceDisplay = document.getElementById('final_price_display');
    const proofWalkinInput = document.getElementById('proof_walkin');
    const downpaymentWalkinInput = document.getElementById('downpayment_walkin'); 
    const proofOnlineInput = document.getElementById('proof_online');
    const downpaymentOnlineInput = document.getElementById('downpayment_online'); 

    // Service durations passed from PHP
    const SERVICE_DURATIONS = <?php echo json_encode($jsServiceDurations); ?>;

    const DAILY_CAPACITY = 10; 
    const MINIMUM_DOWNPAYMENT = 500.00; // Define minimum downpayment amount

    // --- UPDATED QR Display Functions ---
    // Hide both QR codes when hiding one
    window.hideAllQrCodes = function() {
        gcashQrDisplayEl.style.display = 'none';
        bankQrDisplayEl.style.display = 'none';
    };

    // Gcash functions
    window.showGcashQrCodeDisplay = function() {
        hideAllQrCodes(); // Hide the other one first
        gcashQrDisplayEl.style.display = 'block';
    };
    
    window.hideGcashQrCodeDisplay = function() {
        gcashQrDisplayEl.style.display = 'none';
    };

    // Bank functions (NEW)
    window.showBankQrCodeDisplay = function() {
        hideAllQrCodes(); // Hide the other one first
        bankQrDisplayEl.style.display = 'block';
    };
    
    window.hideBankQrCodeDisplay = function() {
        bankQrDisplayEl.style.display = 'none';
    };
    // --- End UPDATED QR Display Fix ---
    
    // --- START: MODIFIED loadSlotsIntoModal FUNCTION ---
    async function loadSlotsIntoModal(date, durationMinutes = 60) {
        showLoading('Fetching available slots...'); // Show spinner
        slotListEl.innerHTML = '<div class="slots-empty">Loading slots...</div>';
        appointmentTimeSelect.innerHTML = '';

        const url = `../calendar_function/fetch_timeslots_patient.php?date=${encodeURIComponent(date)}&duration=${durationMinutes}`;

        try {
            const res = await fetch(url);
            if (!res.ok) throw new Error('Network error');
            const slots = await res.json();

            const d = new Date(date);
            slotsForDateTitle.textContent = d.toDateString();

            // Check for rest day/fully booked status from fetch_timeslots_patient.php
            if (slots.status === 'restday') {
                slotListEl.innerHTML = `<div class="slots-empty text-danger">Doctor Rest Day: ${slots.reason || 'No appointments can be made.'}</div>`;
                return false;
            }
            if (slots.status === 'fully_booked') {
                 slotListEl.innerHTML = `<div class="slots-empty text-danger">Date Fully Booked. Please select another day.</div>`;
                 return false;
            }

            if (!Array.isArray(slots) || slots.length === 0) {
                slotListEl.innerHTML = '<div class="slots-empty">No available slots in the 5AM - 8PM window.</div>';
                return false;
            }

            // -----------------------------
            // FILTER OUT PAST TIMES FOR TODAY ONLY
            const now = new Date();
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const slotDate = new Date(date);
            slotDate.setHours(0, 0, 0, 0);

            const isToday = slotDate.getTime() === today.getTime();

            const filteredSlots = slots.filter(slot => {
                if (!slot.time) return false;
                
                // If this is not today, keep all slots
                if (!isToday) return true; 

                // For today, remove past times
                let slotDateTime = new Date(date);

                // Handle AM/PM format
                const ampmMatch = slot.time.match(/(\d+):(\d+)\s*(AM|PM)/i);
                if (ampmMatch) {
                    let hours = parseInt(ampmMatch[1], 10);
                    const minutes = parseInt(ampmMatch[2], 10);
                    const ampm = ampmMatch[3].toUpperCase();
                    if (ampm === "PM" && hours < 12) hours += 12;
                    if (ampm === "AM" && hours === 12) hours = 0;
                    slotDateTime.setHours(hours, minutes, 0, 0);
                } else {
                    // Assuming 24-hour format if no AM/PM
                    const [h, m] = slot.time.split(':').map(Number);
                    slotDateTime.setHours(h, m, 0, 0);
                }

                // Check if the slot time is in the future or now
                return slotDateTime >= now;
            });
            // -----------------------------

            slotListEl.innerHTML = '';
            const isHourlyDefault = durationMinutes === 60 && !serviceSelect.value;

            filteredSlots.forEach(slot => {
                // Use slot.time as the value for the time field and slot.time_label or slot.time for display
                const label = slot.time_label || slot.time || '';
                const status = (slot.status || '').toLowerCase(); 
                const div = document.createElement('div');
                
                div.classList.add('slot');
                if (status === 'lunchbreak') {
                    div.classList.add('lunchbreak');
                } else {
                    div.classList.add(status);
                }

                let displayLabel = label;
                if(status === 'available') {
                    displayLabel += isHourlyDefault ? ' • Standard Slot' : ` • Available (${durationMinutes} min)`;
                } else if(status === 'booked') displayLabel += ' • Booked';
                else if(status === 'completed') displayLabel += ' • Completed';
                else if(status === 'lunchbreak') displayLabel = 'LUNCH BREAK: 12:00 PM - 1:00 PM'; // Custom label for lunch
                else if(status === 'restday') displayLabel += ' • Rest Day';

                div.textContent = displayLabel;

                // Only make available slots clickable
                if (status === 'available') {
                    div.addEventListener('click', function () {
                        appointmentTimeSelect.innerHTML = '';
                        const opt = document.createElement('option');
                        // Use the raw time for the value
                        opt.value = slot.time.split(' • ')[0]; 
                        // Use the human-readable label for display
                        opt.textContent = displayLabel; 
                        appointmentTimeSelect.appendChild(opt);
                        document.querySelectorAll('#slotList .slot').forEach(s => s.classList.remove('selected'));
                        div.classList.add('selected');
                    });
                }
                slotListEl.appendChild(div);
            });
            
            const hasAvailable = filteredSlots.some(s => (s.status || '').toLowerCase() === 'available');
            if (!hasAvailable) appointmentTimeSelect.innerHTML = '<option value="">No available times</option>';

            return hasAvailable; // true if at least one slot is available

        } catch (err) {
            slotListEl.innerHTML = '<div class="slots-empty">Failed to load slots.</div>';
            appointmentTimeSelect.innerHTML = '<option value="">Error</option>';
            return false;
        } finally {
            hideLoading(); // Hide spinner when fetching is done
        }
    }
    // --- END: MODIFIED loadSlotsIntoModal FUNCTION ---

    // --- START: New showFirstAvailableSlot FUNCTION (Loading added) ---
    async function showFirstAvailableSlot(startDate, preselectedServiceId) {
        showLoading('Searching for next available date...');
        let dateToCheck = new Date(startDate);
        const duration = SERVICE_DURATIONS[preselectedServiceId] || 60;
        
        // Local today at midnight
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        // Check today + next 13 days (2 weeks total)
        for (let i = 0; i < 14; i++) { 
            // Skip past dates (shouldn't happen if startDate is correct, but safe check)
            const checkDate = new Date(dateToCheck);
            checkDate.setHours(0, 0, 0, 0);

            if (checkDate < today) {
                dateToCheck.setDate(dateToCheck.getDate() + 1);
                continue;
            }

            const yyyy = dateToCheck.getFullYear();
            const mm = String(dateToCheck.getMonth() + 1).padStart(2, '0');
            const dd = String(dateToCheck.getDate()).padStart(2, '0');
            const dateStr = `${yyyy}-${mm}-${dd}`;

            // loadSlotsIntoModal handles its own loading status, we check the return value
            const hasAvailable = await loadSlotsIntoModal(dateStr, duration);

            if (hasAvailable) {
                hideLoading(); // Hide search spinner on success
                // Also update the fullcalendar view to show the month of the first available date
                calendar.gotoDate(dateStr); 
                
                appointmentDateInput.value = dateStr;
                modal.show();
                return;
            }

            // Move to next day
            dateToCheck.setDate(dateToCheck.getDate() + 1);
        }

        // Hide spinner if no slots found after search loop completes
        hideLoading(); 

        // No available slots in the next 2 weeks
        appointmentDateInput.value = '';
        modal.hide();
        messageModalLabel.textContent = 'No Slots Found';
        modalMessageContent.innerHTML = `<div class="alert alert-info" role="alert">
                <h4 class="alert-heading">🚫 No Availability</h4>
                <p>The selected service has no available slots in the next 2 weeks. Please check the calendar for later dates or select another service.</p>
            </div>`;
        messageModal.show();
    }
    // --- END: New showFirstAvailableSlot FUNCTION ---

    
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        selectable: true,
        events: '../calendar_function/fetch_appointments_patient.php',
        
        // --- MODIFIED dateClick FUNCTION ---
        dateClick: async function(info) {
            const date = info.dateStr;
            const today = new Date().toISOString().split('T')[0];
            
            if (date < today) { 
                alert("You cannot book an appointment on a past date."); 
                return; 
            }

            // 1. Check for Fully Booked or Rest Day Events on this date
            const eventsOnDay = calendar.getEvents().filter(event => {
                // Look for block events with status 'fully_booked' or 'restday'
                return event.startStr === date && 
                       (event.extendedProps.status === 'fully_booked' || event.extendedProps.status === 'restday');
            });

            if (eventsOnDay.length > 0) {
                const eventData = eventsOnDay[0].extendedProps;
                const status = eventData.status;
                
                modal.hide(); // Close the booking modal just in case it's open
                hideAllQrCodes(); // Hide any floating QR

                if (status === 'fully_booked') {
                    const message = `
                        <div class="alert alert-danger" role="alert">
                            <h4 class="alert-heading">🚫 Date Fully Booked</h4>
                            <p>The date ${date} has reached its maximum appointment capacity (${DAILY_CAPACITY} appointments). Please select another day to book your appointment.</p>
                        </div>
                    `;
                    messageModalLabel.textContent = 'Cannot Book Appointment';
                    modalMessageContent.innerHTML = message;
                    messageModal.show();
                    return; // STOP: Do not open the booking form
                }

                if (status === 'restday') {
                    const reason = eventData.reason || 'No specific reason provided in the database.';
                    const message = `
                        <div class="alert alert-warning" role="alert">
                            <h4 class="alert-heading">⚠️ Doctor Not Available</h4>
                            <p>The date ${date} is marked as a Rest Day for the doctor.</p>
                            <hr>
                            <p class="mb-0">Reason: ${reason}</p>
                        </div>
                    `;
                    messageModalLabel.textContent = 'Doctor Not Available';
                    modalMessageContent.innerHTML = message;
                    messageModal.show();
                    return; // STOP: Do not open the booking form
                }
            }
            
            // --- Original logic to open the booking modal ---
            const selectedServiceId = serviceSelect.value;
            const duration = SERVICE_DURATIONS[selectedServiceId] || 60; 

            appointmentDateInput.value = date;
            appointmentTimeSelect.innerHTML = '';
            
            // Use the new loadSlotsIntoModal (which handles its own loading spinner)
            await loadSlotsIntoModal(date, duration); 
            modal.show();
        },
        // --- END MODIFIED dateClick FUNCTION ---
        
        eventDidMount: function(info) {
            const status = (info.event.extendedProps.status || '').toLowerCase();
            // Assign CSS classes based on status for block events
            if (status === 'booked') info.el.classList.add('status-booked');
            else if (status === 'completed') info.el.classList.add('status-completed');
            else if (status === 'restday') info.el.classList.add('status-restday');
            else if (status === 'fully_booked') info.el.classList.add('status-fully_booked'); 
        },
        
        eventContent: function(arg) {
            const status = arg.event.extendedProps.status;
            const count = arg.event.extendedProps.count; 

            let contentHtml;
            
            // Render text for block events (Fully Booked, Rest Day)
            if (status === 'fully_booked' || status === 'restday') {
                 contentHtml = `<div style="font-weight:700; font-size:0.95rem; text-align:center;">${arg.event.title}</div>`;
            } 
            // Render text for counting events
            else if ((status === 'booked' || status === 'completed' || status === 'pending_payment') && count > 0) {
                contentHtml = `<div style="font-weight:700; font-size:0.95rem; text-align:center;">${arg.event.title}</div>`;
            } else {
                contentHtml = `<div style="font-weight:700; font-size:0.95rem; text-align:center;">${arg.event.title}</div>`;
            }
            
            return { html: contentHtml };
        }
    });
    calendar.render();


    // --- START: New logic after calendar.render() for pre-selected service ---
    if (typeof PRESELECTED_SERVICE_ID !== 'undefined' && PRESELECTED_SERVICE_ID > 0) {
        serviceSelect.value = PRESELECTED_SERVICE_ID;

        const today = new Date().toISOString().split('T')[0];

        // This calls the standalone showFirstAvailableSlot function
        showFirstAvailableSlot(today, PRESELECTED_SERVICE_ID);
    }
    // --- END: New logic after calendar.render() ---

    // Utility function to capitalize words
    function ucwords (str) {
        return (str + '').replace(/^([a-z])|\s+([a-z])/g, function ($1) {
            return $1.toUpperCase();
        });
    }


    modalEl.addEventListener('hidden.bs.modal', function () {
        slotListEl.innerHTML = '<div class="slots-empty">No date selected</div>';
        appointmentTimeSelect.innerHTML = '';
        slotsForDateTitle.textContent = 'Select a date to view slots';
        appointmentForm.reset();
        togglePaymentForms(); 
        setupInputFocus(downpaymentWalkinInput, '0.00'); // Re-apply focus fix
        setupInputFocus(downpaymentOnlineInput, '500.00'); // Re-apply focus fix
        hideAllQrCodes(); // IMPORTANT: Hide floating QRs on modal close
    });
    
    serviceSelect.addEventListener('change', async function() {
        const selectedDate = appointmentDateInput.value;
        const selectedServiceId = this.value;
        
        appointmentTimeSelect.innerHTML = '';
        
        // Re-load slots only if a date is already selected
        if (selectedDate) {
            const duration = SERVICE_DURATIONS[selectedServiceId] || 60;
            // Use the new loadSlotsIntoModal (which handles its own loading spinner)
            await loadSlotsIntoModal(selectedDate, duration); 
        }
        togglePaymentForms(); 
    });

    // --- Payment Form Toggling & Calculation Logic (Discount Removed) ---
    window.togglePaymentForms = function() {
        const primaryMethod = document.querySelector('input[name="primary_method"]:checked')?.value;
        const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
        
        // --- 1. PRICE CALCULATION (NO DISCOUNT) ---
        let basePrice = selectedOption && selectedOption.value ? parseFloat(selectedOption.getAttribute('data-price')) : 0;
        let finalPrice = basePrice;
        
        // Update Display Fields
        finalPriceDisplay.textContent = `₱${finalPrice.toFixed(2)}`;
        // --- END CALCULATION ---

        // 2. Reset all required attributes/names for a clean slate
        proofWalkinInput.removeAttribute('required');
        downpaymentWalkinInput.removeAttribute('required');
        downpaymentWalkinInput.name = 'amount_paid_walkin_hidden'; 
        proofOnlineInput.removeAttribute('required');
        downpaymentOnlineInput.removeAttribute('required');
        downpaymentOnlineInput.name = 'amount_paid_online_hidden'; 
        
        // Set default values if empty
        if (downpaymentWalkinInput.value === '') downpaymentWalkinInput.value = '0.00';
        
        if (primaryMethod === 'Walk-in') {
            walkInGroup.style.display = 'block';
            onlineGroup.style.display = 'none';
            
            // Walk-in requirements (Downpayment)
            proofWalkinInput.setAttribute('required', 'required');
            downpaymentWalkinInput.setAttribute('required', 'required');
            downpaymentWalkinInput.name = 'amount_paid'; // Use the generic 'amount_paid' field for submission
            
        } else { // primaryMethod === 'Online'
            walkInGroup.style.display = 'none';
            onlineGroup.style.display = 'block';

            // Online requirements (Downpayment)
            onlineProofLabel.textContent = 'Image Proof of Downpayment';
            proofOnlineInput.setAttribute('required', 'required');
            downpaymentOnlineInput.setAttribute('required', 'required');
            
            // Use the online downpayment input for the final submission name
            downpaymentOnlineInput.name = 'amount_paid';
        }
        hideAllQrCodes(); // Hide QRs when toggling payment method
    }


    // --- CRITICAL CHANGE: Form Submission Handler (File Renaming Fix) ---
    appointmentForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        
        togglePaymentForms(); 
        hideAllQrCodes(); // Hide the floating QR codes just in case they're open

        const primaryMethod = document.querySelector('input[name="primary_method"]:checked').value;
        
        // Determine the active downpayment input element
        let activeDownpaymentInput;
        if (primaryMethod === 'Walk-in') {
            activeDownpaymentInput = downpaymentWalkinInput;       
        } else { // primaryMethod === 'Online'
            activeDownpaymentInput = downpaymentOnlineInput;       
        }

        const downpaymentAmount = parseFloat(activeDownpaymentInput.value);

        // --- VALIDATION: CHECK MINIMUM DOWNPAYMENT ---
        if (downpaymentAmount < MINIMUM_DOWNPAYMENT) {
            alert(`🚫 The minimum downpayment required for booking is ₱${MINIMUM_DOWNPAYMENT.toFixed(2)}.`);
            // Set focus back to the downpayment input for correction
            activeDownpaymentInput.focus();
            return; 
        }
        // ---------------------------------------------
        
        // -------------------------------------------------------------------
        // FIX: RENAME THE ACTIVE FILE INPUT TO A CONSISTENT NAME FOR SUBMISSION
        // -------------------------------------------------------------------
        let activeFileInput;
        let unusedFileInput;

        if (primaryMethod === 'Walk-in') {
            activeFileInput = proofWalkinInput;        
            unusedFileInput = proofOnlineInput;       
        } else { // primaryMethod === 'Online'
            activeFileInput = proofOnlineInput;        
            unusedFileInput = proofWalkinInput;       
        }

        // Temporarily rename the active input to a consistent name
        const originalActiveName = activeFileInput.name;
        const originalUnusedName = unusedFileInput.name;
        
        activeFileInput.name = 'uploaded_proof_image';
        // Temporarily rename the unused input to ensure it's ignored
        unusedFileInput.name = 'unused_proof_image'; 
        
        // Now create FormData with the new, clean names
        const formData = new FormData(this);
        
        // Restore original names immediately after creating FormData
        activeFileInput.name = originalActiveName; 
        unusedFileInput.name = originalUnusedName; 
        
        // Also explicitly remove the 'unused' image field from the FormData
        formData.delete('unused_proof_image');
        // -------------------------------------------------------------------
        
        // --- CLEAN UP UNUSED AMOUNT FIELDS ---
        // Clean up the unused downpayment field 
        if (primaryMethod === 'Walk-in') {
            formData.delete('amount_paid_online_hidden'); 
        } else { // primaryMethod === 'Online'
            formData.delete('amount_paid_walkin_hidden'); 
        }
        
        // Remove old installment and payment type fields completely 
        formData.delete('payment_type'); 
        formData.delete('installment_term');
        formData.delete('monthly_payment');

        // --- END CLEAN UP ---
        
        if (!formData.get('appointment_time')) { alert('Please select an available time.'); return; }
        if (!formData.get('service_id')) { alert('Please select a service.'); return; } 

        
        try {
            showLoading('Submitting appointment and payment proof...'); // Show spinner for submission
            
            const res = await fetch('../calendar_function/insert_appointment_and_payment_patient.php', {
                method: 'POST', 
                body: formData // Send as FormData for file upload
            });
            const resp = await res.json();
            
            if (resp.status === 'success') {
                // 1. Hide the modal immediately
                modal.hide();
                
                // 2. Refetch events on the calendar (Asynchronous operation)
                showLoading('Success! Updating calendar...');
                await calendar.refetchEvents();
                
                // 3. Display the final success message in the dedicated modal
                messageModalLabel.textContent = 'Appointment Confirmed!';
                modalMessageContent.innerHTML = `
                    <div class="alert alert-success" role="alert">
                        <h4 class="alert-heading">✅ Success!</h4>
                        <p>Your appointment has been successfully booked and your downpayment details have been received.</p>
                        <hr>
                        <p class="mb-0">Status: Pending Verification (Secretary will review proof).</p>
                    </div>
                `;
                messageModal.show();
                
            } else {
                // Handle submission errors
                messageModalLabel.textContent = 'Submission Failed';
                modalMessageContent.innerHTML = `
                    <div class="alert alert-danger" role="alert">
                        <h4 class="alert-heading">❌ Error!</h4>
                        <p>${resp.message || 'Unable to submit your request due to a server error.'}</p>
                    </div>
                `;
                messageModal.show();
            }
        } catch (err) { 
            // Handle network/critical errors
            messageModalLabel.textContent = 'Critical Error';
            modalMessageContent.innerHTML = `
                <div class="alert alert-danger" role="alert">
                    <h4 class="alert-heading">❌ Network Error!</h4>
                    <p>The appointment submission failed. Please check your internet connection or contact the clinic.</p>
                </div>
            `;
            messageModal.show();
        } finally {
            hideLoading(); // Hide spinner regardless of success or failure
        }
    });

    // ------------------------------------------------------------------
    // Helper function to clear 0.00/500.00 on focus and restore on blur
    // ------------------------------------------------------------------
    function setupInputFocus(inputElement, defaultValue) {
        inputElement.addEventListener('focus', function() {
            if (this.value === defaultValue) {
                this.value = '';
            }
        });
        inputElement.addEventListener('blur', function() {
            if (this.value === '') {
                this.value = defaultValue;
            }
        });
    }
    
    // Initial call to set form state correctly
    togglePaymentForms();

    // Apply the focus/blur fix immediately after initial togglePaymentForms runs
    setupInputFocus(downpaymentWalkinInput, '0.00');
    setupInputFocus(downpaymentOnlineInput, '500.00'); 
    // ------------------------------------------------------------------


    // 🔍 Calendar Search Bar Logic (Existing)
    const yearSelect = document.getElementById('searchYear');
    const monthSelect = document.getElementById('searchMonth');
    const dayInput = document.getElementById('searchDay');
    const goButton = document.getElementById('goToDate');

    const currentYear = new Date().getFullYear();
    for (let y = currentYear - 2; y <= currentYear + 2; y++) {
        const opt = document.createElement('option');
        opt.value = y; opt.textContent = y;
        if (y === currentYear) opt.selected = true;
        yearSelect.appendChild(opt);
    }

    goButton.addEventListener('click', function () {
        const y = yearSelect.value;
        const m = monthSelect.value;
        const d = dayInput.value;
        if (y && m !== "" && d) {
            const targetDate = new Date(y, m, d);
            calendar.gotoDate(targetDate);
        } else if (y && m !== "") {
            const targetDate = new Date(y, m);
            calendar.gotoDate(targetDate);
        } else {
            alert("Please select at least a year and month.");
        }
    });
});
</script>
</body>
</html>