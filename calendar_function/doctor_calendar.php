<?php
// doctor_calendar.php
require_once '../calendar_function/calendar_conn.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>DentiTrack | Doctor Calendar</title>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<style>
/* --- BASE & CALENDAR STYLES (MATCHES SECRETARY) --- */
.calendar-title { 
    text-align: center; 
    color: #333; 
}
#calendar { 
    max-width: 900px; 
    margin: 20px auto; 
    background: #fff; 
    border-radius: 10px; 
    box-shadow: 0 3px 8px rgba(0,0,0,0.1); 
    padding: 15px; 
}

/* --- LEGEND & CONTROLS --- */
.legend { 
    display: flex; 
    justify-content: center; 
    gap: 15px; 
    margin-bottom: 10px; 
}
.legend-item { 
    display: flex; 
    align-items: center; 
    gap: 6px; 
    font-weight: 500;
}
.color-box { 
    width: 18px; 
    height: 18px; 
    border-radius: 3px; 
}
.search-container { 
    text-align: center; 
    margin: 15px 0; 
    position: relative; 
}
.search-container select, .search-container button, .search-container input { 
    padding: 6px 10px; 
    margin: 0 5px; 
    border-radius: 6px; 
    border: 1px solid #ccc; 
    font-size: 15px; 
}
.search-container button { 
    background-color: #007bff; 
    color: #fff; 
    border: none; 
    cursor: pointer; 
}
.search-container button:hover { 
    background-color: #0056b3; 
}

/* --- AUTOCPMLETE LIST --- */
#patientList { 
    position: absolute; 
    left: 50%; 
    transform: translateX(-50%); 
    width: 250px; 
    background: #fff; 
    border: 1px solid #ccc; 
    border-top: none; 
    border-radius: 0 0 6px 6px; 
    max-height: 180px; 
    overflow-y: auto; 
    box-shadow: 0 3px 8px rgba(0,0,0,0.1); 
    z-index: 1000; 
    display: none; 
}
#patientList div { 
    padding: 8px 10px; 
    cursor: pointer; 
}
#patientList div:hover { 
    background-color: #f0f0f0; 
}

/* --- MODAL STRUCTURE (MATCHES SECRETARY) --- */
.modal-secretary { 
    display: none; 
    position: fixed; 
    z-index: 9999; 
    top: 0; 
    left: 0; 
    width: 100%; 
    height: 100%; 
    background-color: rgba(0,0,0,0.5); 
    justify-content: center; 
    align-items: center; 
}
.modal-content-secretary { 
    background: #fff; 
    border-radius: 12px; 
    padding: 20px; 
    width: 500px; 
    max-height: 85vh; 
    overflow-y: auto; 
    box-shadow: 0 5px 15px rgba(0,0,0,0.2); 
    position: relative; 
}
.modal-content-secretary h3 { 
    margin-top: 0; 
    font-size: 1.4em; 
    color: #333; 
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
    margin-bottom: 15px;
}

/* --- CLOSE BUTTON (MATCHES SECRETARY) --- */
.close-btn { 
    position: absolute; 
    top: 10px; 
    right: 15px; 
    background: #ccc; 
    border: none; 
    border-radius: 50%; 
    width: 26px; 
    height: 26px; 
    font-weight: bold; 
    cursor: pointer; 
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 1.25rem;
    line-height: 1;
}
.close-btn:hover { 
    background: #999; 
    color: white; 
}

/* --- APPOINTMENT CARD (Secretary Style) --- */
.appointment-card { 
    background: #f8f9fa;
    border-left: 5px solid #007bff;
    border-radius: 8px; 
    box-shadow: 0 2px 5px rgba(0,0,0,0.1); 
    padding: 12px; 
    margin-bottom: 10px; 
    cursor: pointer; 
    transition: transform 0.1s ease, box-shadow 0.1s ease; 
}
.appointment-card:hover { 
    transform: scale(1.02); 
    box-shadow: 0 3px 10px rgba(0,0,0,0.15); 
}

/* --- DETAIL ROWS (MATCHES SECRETARY) --- */
.info-row { 
    display: flex; 
    justify-content: space-between; 
    margin: 6px 0; 
    border-bottom: 1px solid #eee; 
    padding-bottom: 4px; 
}
.info-label { 
    font-weight: 600; 
    color: #444; 
}
.info-value { 
    color: #333; 
    text-align: right; 
}

/* --- Custom Utility/Button Classes (Replacing Bootstrap) --- */
.btn {
    border: none;
    border-radius: 6px;
    padding: 8px 14px;
    color: white;
    cursor: pointer;
    font-weight: 500;
    margin-left: 10px; /* Separator for buttons */
}
.btn-success { background-color: #28a745; }
.btn-success:hover { background-color: #218838; }
.btn-danger { background-color: #dc3545; }
.btn-danger:hover { background-color: #c82333; }
.btn-secondary { background-color: #6c757d; color: white; }
.btn-secondary:hover { background-color: #5a6268; }

.form-control {
    width: 98%;
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 4px;
    box-sizing: border-box;
}

/* --- Text/Spacing Utilities (Replacing Bootstrap) --- */
.mb-3 { margin-bottom: 15px; } /* Equivalent to mb-3 */
.mt-3 { margin-top: 15px; }   /* Equivalent to mt-3 */
.text-secondary { color: #6c757d; }
.fw-semibold { font-weight: 600; }
.text-center { text-align: center; }

/* --- DOCTOR'S CALENDAR OVERRIDES --- */
.status-approved {
    background-color: #C4A1D8 !important; /* Purple for Approved */
    color: white !important;
}
.status-restday {
    background-color: #8FD19E !important;
    color: black !important;
}

/* *** FIX: ADD NEW STYLE FOR CANCELED CALENDAR EVENTS (RED) *** */
.status-canceled-event {
    background-color: #FF6961 !important; /* Red for Canceled */
    color: white !important;
}

/* FullCalendar default is blue for Booked/generic events, which matches the legend. */

.fc-day-today {
    background-color: #e0e0e0 !important;
    color: #000 !important;
    font-weight: 600;
    border: 2px solid #b0b0b0 !important;
    border-radius: 6px;
}

/* --- NEW: Appointment Card Status Colors (Left Border) --- */
.status-completed {
    border-left-color: #28a745 !important; 
}
.status-canceled {
    border-left-color: #FF6961 !important; /* Changed from yellow to red for consistency */
}
.status-booked, .status-approved {
    border-left-color: #007bff !important; 
}
/* --- NEW: Appointment Card Status Colors (Left Border) --- */

</style>
</head>
<body>

<h2 class="calendar-title"style="text-align: center; color: #333;">🩺 Doctor Appointment Calendar</h2>

<div class="legend">
  <div class="legend-item"><div class="color-box" style="background:#4A90E2;"></div> Booked</div>
<div class="legend-item"><div class="color-box" style="background:#B19CD9;"></div> Approved</div>
  <div class="legend-item"><div class="color-box" style="background:#8FD19E;"></div> Rest Day</div>
  <div class="legend-item"><div class="color-box" style="background:#28a745"></div> Complete</div>
  <div class="legend-item"><div class="color-box" style="background:#FF6961;"></div> Canceled</div>
</div>

<div class="search-container">
  <select id="yearSelect"></select>
  <select id="monthSelect"></select>
  <select id="daySelect"></select>
  <button class="search-btn" id="goDateBtn">Go</button>
  <form id="searchForm" style="display:inline; position:relative;">
    <input type="text" id="searchName" placeholder="🔎 Search by patient name..." style="width:220px;">
    <button type="submit" id="searchNameBtn">Search</button>
    <div id="patientList"></div>
  </form>
</div>

<div id="calendar"></div>

<div id="restDayModal" class="modal-secretary">
  <div class="modal-content-secretary">
    <button class="close-btn" id="closeRest">×</button>
    <h3>🩺 Rest Day Management</h3>
    <div id="restDayInfo" class="mb-3 text-secondary" style="font-size:15px;color:#333;"></div>
    <form id="restDayForm" style="padding-top:10px;">
      <div class="mb-3">
        <label class="fw-semibold">Reason for Rest Day:</label>
        <textarea id="restDayReason" class="form-control" rows="3"></textarea>
      </div>
      <input type="hidden" id="restDayDate">
    </form>
    <div class="mt-3" style="text-align:right;">
      <button type="button" id="setRestBtn" class="btn btn-success">Set Rest Day</button>
      <button type="button" id="removeRestBtn" class="btn btn-danger">Remove Rest Day</button>
      <button type="button" id="cancelRestBtn" class="btn btn-secondary">Cancel</button>
    </div>
  </div>
</div>

<div id="appointmentModal" class="modal-secretary">
  <div class="modal-content-secretary">
    <button class="close-btn" id="closeDetail">×</button>
    <h3>📋 Appointment Details</h3>
    <div id="appointmentDetails" style="padding-top: 10px;"></div>
    <div class="mt-3" style="text-align:right;">
      <button type="button" id="closeDetailsBtn" class="btn btn-secondary">Close</button>
    </div>
  </div>
</div>

<div id="dateAppointmentsModal" class="modal-secretary">
  <div class="modal-content-secretary">
    <button class="close-btn" id="closeList">×</button>
    <h3><span id="dateAppointmentsModalLabel"></span></h3>
    <div id="dateAppointmentsBody" style="max-height: 70vh; overflow-y: auto; padding-top: 10px;">
        </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- Initial setup of controls ---
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    const daySelect = document.getElementById('daySelect');
    const now = new Date();
    
    const populateSelect = (selectEl, start, end, selected) => {
        for (let i = start; i <= end; i++) {
            const opt = document.createElement('option');
            opt.value = i; 
            opt.textContent = i; 
            if (i === selected) opt.selected = true; 
            selectEl.appendChild(opt);
        }
    };

    populateSelect(yearSelect, now.getFullYear() - 1, now.getFullYear() + 1, now.getFullYear());
    const months=['January','February','March','April','May','June','July','August','September','October','November','December'];
    months.forEach((m,i)=>{const opt=document.createElement('option'); opt.value=i+1; opt.textContent=m; if(i===now.getMonth()) opt.selected=true; monthSelect.appendChild(opt);});
    populateSelect(daySelect, 1, 31, now.getDate());

    // --- Modal DOM References ---
    const calendarEl = document.getElementById('calendar');
    const restModal = document.getElementById('restDayModal');
    const appointmentModal = document.getElementById('appointmentModal');
    const dateAppointmentsModal = document.getElementById('dateAppointmentsModal');

    // --- References to Modal Elements ---
    const restInfo = document.getElementById('restDayInfo');
    const restDate = document.getElementById('restDayDate');
    const reasonField = document.getElementById('restDayReason');
    const setBtn = document.getElementById('setRestBtn');
    const removeBtn = document.getElementById('removeRestBtn');
    const appointmentDetails = document.getElementById('appointmentDetails');
    const dateAppointmentsBody = document.getElementById('dateAppointmentsBody');
    const dateAppointmentsLabel = document.getElementById('dateAppointmentsModalLabel');
    
    const detailStatusMsg = document.createElement('p');
    detailStatusMsg.style.marginTop = '10px';
    detailStatusMsg.style.fontWeight = 'bold';
    detailStatusMsg.id = 'detailStatusMessage';
    detailStatusMsg.style.textAlign = 'center';

    // --- Close Modal Functions ---
    document.getElementById('closeRest').onclick = () => restModal.style.display = 'none';
    document.getElementById('cancelRestBtn').onclick = () => restModal.style.display = 'none';
    
    // Close detail modal and return to the list modal
    const closeDetailModal = () => {
        appointmentModal.style.display = 'none'; 
        // Re-run the daily fetch to refresh the list, in case status changed
        const dateStr = dateAppointmentsLabel.textContent.replace('Appointments on ', '');
        if (dateStr && !dateAppointmentsLabel.textContent.includes('Appointments for')) {
            fetchAppointmentsList(dateStr);
        } else {
            // Only show list modal if we were in a list view
            if(dateAppointmentsBody.innerHTML.trim() !== '<p class="text-center text-muted">Loading appointments...</p>') {
                dateAppointmentsModal.style.display = 'flex';
            }
        }
    };
    document.getElementById('closeDetail').onclick = closeDetailModal;
    document.getElementById('closeDetailsBtn').onclick = closeDetailModal;
    document.getElementById('closeList').onclick = () => dateAppointmentsModal.style.display = 'none';


    // --- Helper Function: Status Update ---
    function updateAppointmentStatus(appointmentId, status) {
        detailStatusMsg.textContent = 'Updating status...';
        detailStatusMsg.style.color = '#ffc107'; // Yellow for 'updating'
        
        fetch('../calendar_function/update_appointment_status_doctor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `appointment_id=${encodeURIComponent(appointmentId)}&status=${encodeURIComponent(status)}&doctor_id=4` 
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                detailStatusMsg.textContent = `Status updated to ${status}!`;
                
                // *** FIX: Conditional color setting for detailStatusMsg (Updated Canceled to Red) ***
                let newColor;
                if (status === 'Completed') newColor = '#28a745'; // Green
                else if (status === 'Canceled') newColor = '#FF6961'; // Red (NEW)
                else newColor = '#007BFF'; // Blue (for Booked/Approved)
                
                detailStatusMsg.style.color = newColor; 
                
                // FIX: Apply color to the current status value in the detail modal
                const currentStatusEl = document.getElementById('currentStatusValue');
                if (currentStatusEl) {
                    currentStatusEl.textContent = status;
                    currentStatusEl.style.color = newColor;
                }
                
                calendar.refetchEvents(); // Refresh calendar events
                
                // Optionally hide buttons after completion/cancellation
                const statusControls = document.getElementById('statusControls');
                if (statusControls) {
                    if (status === 'Completed') {
                        statusControls.innerHTML = `<p style="color:#28a745; font-weight:600;">This appointment is now Completed.</p>`;
                    } else if (status === 'Canceled') {
                        // *** FIX: Use consistent red for the success message ***
                        statusControls.innerHTML = `<p style="color:#FF6961; font-weight:600;">This appointment is now Canceled.</p>`;
                    }
                }

            } else {
                detailStatusMsg.textContent = data.message || 'Error updating status.';
                detailStatusMsg.style.color = '#dc3545'; 
            }
        })
        .catch(err => {
            console.error("Status Update Error:", err);
            detailStatusMsg.textContent = 'Network error during status update.';
            detailStatusMsg.style.color = '#dc3545'; 
        });
    }


    // --- Helper Function: Fetches Details & Shows Detail Modal (GET BOOKING) ---
    function showAppointmentDetails(appointmentId) {
        dateAppointmentsModal.style.display = 'none'; 
        appointmentDetails.innerHTML = '<p class="text-center text-muted">Loading details...</p>';
        detailStatusMsg.textContent = ''; 
        
        fetch('../calendar_function/get_appointment_details_doctor.php?id=' + encodeURIComponent(appointmentId))
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    const a = data.data;
                    
                    let statusControlsHTML = '';
                    let statusColor = '#007BFF'; // Default to Blue for Booked/Approved
                    
                    if (a.status === 'Approved' || a.status === 'Booked') { 
                        statusControlsHTML = `
                            <button class="btn status-btn btn-success" data-status="Completed" data-id="${a.appointment_id}" style="padding: 8px 15px; margin: 5px;">Mark Complete</button>
                            <button class="btn status-btn btn-danger" data-status="Canceled" data-id="${a.appointment_id}" style="padding: 8px 15px; margin: 5px;">Mark Canceled</button>
                        `;
                    } else if (a.status === 'Completed') {
                        statusControlsHTML = `<p style="color:#28a745; font-weight:600;">This appointment is already Completed.</p>`;
                        statusColor = '#28a745';
                    } else if (a.status === 'Canceled') {
                        // *** FIX: Use consistent red for the static 'already Canceled' message ***
                        statusControlsHTML = `<p style="color:#FF6961; font-weight:600;">This appointment is already Canceled.</p>`;
                        statusColor = '#FF6961'; // Red (NEW)
                    } else {
                        // Display generic status message
                        statusControlsHTML = `<p class="text-secondary">No status actions available for current status: ${a.status}</p>`;
                        statusColor = '#6c757d';
                    }

                    appointmentDetails.innerHTML = `
                        <div class="info-row"><span class="info-label">Appointment ID:</span><span class="info-value fw-bold">${a.appointment_id}</span></div>
                        <div class="info-row"><span class="info-label">Patient Name:</span><span class="info-value">${a.patient_name}</span></div>
                        <div class="info-row"><span class="info-label">Email:</span><span class="info-value">${a.email || '-'}</span></div>
                        <div class="info-row"><span class="info-label">Contact:</span><span class="info-value">${a.contact_number || '-'}</span></div>
                        <div class="info-row"><span class="info-label">Gender:</span><span class="info-value">${a.gender || '-'}</span></div>
                        <div class="info-row"><span class="info-label">Age:</span><span class="info-value">${a.age || '-'}</span></div>
                        <div class="info-row"><span class="info-label">Service:</span><span class="info-value">${a.service}</span></div>
                        <div class="info-row"><span class="info-label">Date/Time:</span><span class="info-value">${a.appointment_date || '-'} at ${a.appointment_time || '-'}</span></div>
                        <div class="info-row"><span class="info-label">Comments:</span><span class="info-value">${a.comments || '-'}</span></div>
                        <div class="info-row"><span class="info-label">Current Status:</span><span class="info-value fw-semibold" id="currentStatusValue" style="color:${statusColor};">${a.status}</span></div>
                        
                        <div id="statusControls" style="text-align: center; margin-top: 15px;">
                            ${statusControlsHTML}
                        </div>
                    `;
                    appointmentDetails.appendChild(detailStatusMsg); 
                    
                    appointmentDetails.querySelectorAll('.status-btn').forEach(button => {
                        button.addEventListener('click', (e) => {
                            const status = e.target.getAttribute('data-status');
                            const id = e.target.getAttribute('data-id');
                            updateAppointmentStatus(id, status);
                        });
                    });

                    appointmentModal.style.display = 'flex'; 
                } else {
                    alert('Unable to fetch appointment details. ' + (data.message || ''));
                    dateAppointmentsModal.style.display = 'flex'; 
                }
            }).catch(err => {
                console.error("Error fetching details:", err);
                alert('Error fetching details.');
                dateAppointmentsModal.style.display = 'flex'; 
            });
    }

    // --- Helper Function: Fetches Daily Appointment List (Reusable) ---
    function fetchAppointmentsList(dateStr, term = '') {
        dateAppointmentsLabel.textContent = term ? `Appointments for ${term}` : `Appointments on ${dateStr}`;
        dateAppointmentsBody.innerHTML = '<p class="text-center text-muted">Loading appointments...</p>';

        let fetchUrl;
        if (term) {
            // Patient Search
            fetchUrl = '../calendar_function/fetch_appointments_by_patient_doctor.php?patient=' + encodeURIComponent(term);
        } else {
            // Date Click
            fetchUrl = '../calendar_function/fetch_appointments_by_date_doctor.php?date=' + encodeURIComponent(dateStr);
        }

        fetch(fetchUrl)
            .then(r => r.json())
            .then(data => {
                dateAppointmentsBody.innerHTML = '';
                
                const appointments = data.formatted || data.appointments; 

                if (!appointments || appointments.length === 0) { 
                    const message = data.message || (term ? `No results found for ${term}` : 'No appointments for this date.');
                    dateAppointmentsBody.innerHTML = `<p class="text-center text-muted">${message}</p>`;
                } else {
                    appointments.forEach(a => {
                        const card = document.createElement('div');
                        card.className = 'appointment-card';

                        const statusLower = a.status ? a.status.toLowerCase() : '';
                        let statusColor = '#007BFF'; // Default Blue
                        let statusStyle = ''; // Default no inline style

                        if (statusLower === 'completed') {
                            card.classList.add('status-completed');
                            statusColor = '#28a745';
                            statusStyle = `style="color:${statusColor};"`;
                        } else if (statusLower === 'canceled') {
                            card.classList.add('status-canceled');
                            statusColor = '#FF6961'; // Red (NEW)
                            statusStyle = `style="color:${statusColor};"`;
                        } else {
                            // Covers 'Approved', 'Booked', or any other active status
                            card.classList.add('status-booked');
                            statusStyle = `style="color:${statusColor};"`;
                        }
                        
                        card.innerHTML=`
                            <div><strong>${a.patient_name}</strong> (${a.service})</div>
                            <div style="font-size:14px;">${a.appointment_date ? a.appointment_date + ' at ' : ''}${a.appointment_time}</div>
                            <div ${statusStyle}>Status: ${a.status}</div>`;
                            
                        card.addEventListener('click',()=> showAppointmentDetails(a.appointment_id));
                        dateAppointmentsBody.appendChild(card);
                    });
                }
                dateAppointmentsModal.style.display = 'flex'; 
            })
            .catch(err => {
                console.error("Error fetching list:", err);
                dateAppointmentsBody.innerHTML = '<p class="text-center text-danger">Failed to load appointments.</p>';
                dateAppointmentsModal.style.display = 'flex'; 
            });
    }


    // --- FullCalendar Initialization ---
    let currentFetchUrl='../calendar_function/fetch_appointments_doctor.php';
    const calendar = new FullCalendar.Calendar(calendarEl,{
        initialView:'dayGridMonth',
        selectable:true,
        events: (fetchInfo, successCallback, failureCallback)=>{
            fetch(currentFetchUrl)
                .then(r=>r.json())
                .then(events=>successCallback(events))
                .catch(err=>{console.error(err); failureCallback(err);});
        },
        
        dateClick:function(info){
            restDate.value=info.dateStr; reasonField.value='';
            
            fetch('../calendar_function/check_rest_day_doctor.php?date='+encodeURIComponent(info.dateStr))
            .then(r=>r.json())
            .then(data=>{
                if(data.isRestDay){
                    restInfo.innerHTML=`<strong>${info.dateStr}</strong> is currently a <span style='color:#28a745; font-weight:600;'>Rest Day</span>.<br>${data.reason? 'Reason: '+data.reason:''}`;
                    reasonField.value=data.reason||''; setBtn.style.display='none'; removeBtn.style.display='inline-block';
                }else{ restInfo.innerHTML=`Do you want to mark <strong>${info.dateStr}</strong> as a Rest Day?`; setBtn.style.display='inline-block'; removeBtn.style.display='none'; }
                restModal.style.display = 'flex'; 
            })
            .catch(err=>{console.error(err); alert('Error checking rest day.');});
        },
        
        eventClick:function(info){
            const status = (info.event.extendedProps.status || '').toLowerCase();
            
            // Only proceed if it's NOT a rest day event.
            if(status !== 'rest day'){
                const dateStr = info.event.startStr;
                fetchAppointmentsList(dateStr);
            }
        },
        
        eventDidMount:function(info){
            const status=(info.event.extendedProps.status||'').toLowerCase();
            if(status==='Booked') info.el.classList.add('status-approved'); 
            else if(status==='rest day') info.el.classList.add('status-restday');
            // *** FIX: ADD CANCELED EVENT STYLING ***
            else if(status==='canceled') info.el.classList.add('status-canceled-event'); 
            // 'booked' status will now fall through to the default blue styling.
        },
        eventContent:function(arg){ return { html:`<div class='text-center fw-semibold' style="font-size:0.85rem;">${arg.event.title}</div>`}; }
    });
    calendar.render();

    // --- Go button (Date Search) ---
    document.getElementById('goDateBtn').addEventListener('click',()=>{
        const y=parseInt(yearSelect.value,10);
        const m=parseInt(monthSelect.value,10);
        const d=parseInt(daySelect.value,10);
        if(!y||!m||!d){alert('Invalid date'); return;}
        const dateStr=`${y}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        fetchAppointmentsList(dateStr);
    });

    // --- Search autocomplete & search (Patient Search) ---
    const searchForm=document.getElementById('searchForm');
    const searchInput=document.getElementById('searchName');
    const patientList=document.getElementById('patientList');
    let autocompleteTimer=null;
    const DOCTOR_ID = 4;

    searchInput.addEventListener('keyup',function(){
        const q=this.value.trim();
        if(autocompleteTimer) clearTimeout(autocompleteTimer);
        if(!q){patientList.style.display='none'; return;}
        autocompleteTimer=setTimeout(()=>{
            fetch('../calendar_function/fetch_patients_list_doctor.php?query='+encodeURIComponent(q))
            .then(r=>r.json())
            .then(data=>{
                patientList.innerHTML='';
                if(data.status==='success'&&Array.isArray(data.patients)&&data.patients.length){
                    data.patients.forEach(p=>{
                        const item=document.createElement('div'); item.textContent=p.patient_name;
                        item.addEventListener('click',()=>{ searchInput.value=p.patient_name; patientList.style.display='none'; performPatientSearch(p.patient_name); });
                        patientList.appendChild(item);
                    }); patientList.style.display='block';
                }else patientList.style.display='none';
            }).catch(err=>{console.error(err); patientList.style.display='none';});
        },220);
    });

    document.addEventListener('click', e=>{ if(!e.target.closest('#searchName')&&!e.target.closest('#patientList')) patientList.style.display='none'; });

    searchForm.addEventListener('submit', e=>{ 
        e.preventDefault(); 
        const term=searchInput.value.trim(); 
        if(!term){ 
            currentFetchUrl='../calendar_function/fetch_appointments_doctor.php'; 
            calendar.refetchEvents(); 
            dateAppointmentsModal.style.display = 'none';
            return;
        } 
        performPatientSearch(term); 
    });

    function performPatientSearch(term){
        fetchAppointmentsList(null, term);
    }

    // --- Rest Day Add / Remove ---
    setBtn.addEventListener('click', ()=>{
        const dateVal = restDate.value;
        const reason = reasonField.value.trim() || 'Personal leave';
        if(!dateVal){ alert('No date selected'); return; }
        fetch('../calendar_function/update_rest_day_doctor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `date=${encodeURIComponent(dateVal)}&reason=${encodeURIComponent(reason)}&action=set&doctor_id=${DOCTOR_ID}`
        })
        .then(r=>r.json())
        .then(data=>{
            if(data.status==='added'){ alert(data.message); restModal.style.display = 'none'; calendar.refetchEvents(); }
            else alert('Error adding rest day');
        }).catch(err=>{ console.error(err); alert('Error'); });
    });

    removeBtn.addEventListener('click', ()=>{
        const dateVal = restDate.value;
        if(!dateVal){ alert('No date selected'); return; }
        fetch('../calendar_function/update_rest_day_doctor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `date=${encodeURIComponent(dateVal)}&action=remove&doctor_id=${DOCTOR_ID}`
        })
        .then(r=>r.json())
        .then(data=>{
            if(data.status==='removed'){ alert(data.message); restModal.style.display = 'none'; calendar.refetchEvents(); }
            else alert('Error removing rest day');
        }).catch(err=>{ console.error(err); alert('Error'); });
    });

});
</script>
</body>
</html>