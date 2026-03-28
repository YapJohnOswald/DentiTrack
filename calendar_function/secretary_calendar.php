<?php
// Ensure the database connection file is correctly referenced
require_once '../calendar_function/calendar_conn.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DentiTrack | Secretary Calendar</title>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<style>
/* -------------------------------------------
   Global & Calendar Styles
------------------------------------------- */
body { font-family: sans-serif; }
.calendar-title { text-align: center; color: #333; margin-bottom: 20px; }
#calendar { 
    max-width: 900px; 
    margin: 20px auto; 
    background: #fff; 
    border-radius: 12px; 
    box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
    padding: 20px; 
}

/* -------------------------------------------
   Legend (UPDATED)
------------------------------------------- */
.legend { display: flex; justify-content: center; gap: 15px; margin-bottom: 10px; }
.legend-item { display: flex; align-items: center; gap: 6px; font-size: 0.9em; }
.color-box { width: 18px; height: 18px; border-radius: 3px; }

/* -------------------------------------------
   Search & Navigation
------------------------------------------- */
.search-container { text-align: center; margin: 15px 0; position: relative; }
.search-container select, 
.search-container button, 
.search-container input { 
    padding: 8px 12px; 
    margin: 0 5px; 
    border-radius: 6px; 
    border: 1px solid #ccc; 
    font-size: 15px; 
    vertical-align: middle;
}
.search-container button { 
    background-color: #007bff; 
    color: #fff; 
    border: none; 
    cursor: pointer; 
}
.search-container button:hover { background-color: #0056b3; }

/* Patient Search Autocomplete */
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
    box-shadow: 0 5px 10px rgba(0,0,0,0.1); 
    z-index: 1000; 
    display: none; 
}
#patientList div { padding: 8px 10px; cursor: pointer; font-size: 15px; text-align: left; }
#patientList div:hover { background-color: #f0f0f0; }

/* -------------------------------------------
   FullCalendar Overrides
------------------------------------------- */
.fc-day-today { 
    background-color: #e0e0e0 !important; 
    color: #000 !important; 
    font-weight: 600; 
    border: 2px solid #b0b0b0 !important; 
    border-radius: 6px; 
}

/* FullCalendar Event Status Classes (UPDATED COLORS) */
/*.fc-event.status-pending { background-color: #FFD966 !important; color: black !important; border-color: #FFD966 !important; } /* Yellow - Pending */
.fc-event.status-approved { background-color: #B19CD9 !important; color: white !important; border-color: #B19CD9 !important; } /* Light Purple - Approved */
.fc-event.status-booked { background-color: #4A90E2 !important; color: white !important; border-color: #4A90E2 !important; } /* Blue */
.fc-event.status-completed { background-color: #28a745 !important; color: white !important; border-color: #28a745 !important; } /* Green */
.fc-event.status-cancelled, .fc-event.status-declined { background-color: #FF6961 !important; color: white !important; border-color: #FF6961 !important; } /* Reddish - Cancelled/Declined */
.fc-event.status-restday { background-color: #8FD19E !important; color: black !important; border-color: #8FD19E !important; } /* Light Green (Rest Day) */


/* -------------------------------------------
   Modal Styles
------------------------------------------- */
.modal { 
    display: none; /* Hidden by default */
    position: fixed; 
    z-index: 9999; 
    top: 0; 
    left: 0; 
    width: 100%; 
    height: 100%; 
    background-color: rgba(0,0,0,0.6); 
    justify-content: center; 
    align-items: center; 
}
.modal.active { display: flex; } /* Shown when 'active' class is added */

.modal-content { 
    background: #fff; 
    border-radius: 12px; 
    padding: 25px; 
    width: 450px; 
    max-height: 90vh; 
    overflow-y: auto; 
    box-shadow: 0 8px 25px rgba(0,0,0,0.3); 
    position: relative; 
}
.modal h3 { 
    margin-top: 0; 
    font-size: 1.5em; 
    color: #333; 
    margin-bottom: 15px;
}
.close-btn { 
    position: absolute; 
    top: 15px; 
    right: 15px; 
    background: #e0e0e0; 
    border: none; 
    border-radius: 50%; 
    width: 30px; 
    height: 30px; 
    font-size: 1.2em;
    font-weight: bold; 
    cursor: pointer; 
    transition: background 0.2s;
}
.close-btn:hover { background: #ccc; color: #333; }

/* Appointment List Cards */
.appointment-card { 
    background: #f9f9f9; 
    border: 1px solid #eee;
    border-radius: 8px; 
    padding: 15px; 
    margin-bottom: 10px; 
    cursor: pointer; 
    transition: all 0.2s ease; 
}
.appointment-card:hover { 
    background: #f0f0f0;
    transform: translateY(-2px); 
    box-shadow: 0 4px 8px rgba(0,0,0,0.1); 
}
.appointment-card strong { color: #0056b3; }

/* Appointment Detail Rows */
.info-row { 
    display: flex; 
    justify-content: space-between; 
    margin: 8px 0; 
    padding-bottom: 4px; 
    border-bottom: 1px dotted #eee; 
}
.info-label { font-weight: 600; color: #444; }
.info-value { color: #333; text-align: right; word-break: break-word; }

/* Action Buttons */
#actionButtons { 
    margin-top: 20px; 
    display: flex; 
    justify-content: flex-end; 
    gap: 10px;
}
.btn { 
    border: none; 
    border-radius: 6px; 
    padding: 10px 16px; 
    color: white; 
    cursor: pointer; 
    font-weight: 600; 
    transition: background-color 0.2s;
}
.btn-approve { background-color: #B19CD9; } /* Light Purple for Approved */ .btn-approve:hover { background-color: #9c89c4; }
.btn-decline { background-color: #dc3545; } .btn-decline:hover { background-color: #c82333; }
.btn-complete { background-color: #28a745; } /* Green for Completed */ .btn-complete:hover { background-color: #218838; }
.btn-cancel { background-color: #FF6961; color: white; } /* Red for Cancel */ .btn-cancel:hover { background-color: #e65249; }
.btn-close-modal { background-color: #6c757d; color: white; } 
.btn-close-modal:hover { background-color: #5a6268; }

/* Status Colors for text in modals */
/*.status-pending { color: #FFD966; } /* Yellow */
.status-approved { color: #B19CD9; } /* Light Purple */
.status-booked { color: #4A90E2; }
.status-completed { color: #28a745; }
.status-cancelled, .status-declined { color: #FF6961; } /* Reddish */
</style>
</head>
<body>

<h2 class="calendar-title">🗂️ Secretary Appointment Calendar</h2>

<div class="legend">
  <!--<div class="legend-item"><div class="color-box" style="background:#FFD966;"></div> Pending</div>-->
  <div class="legend-item"><div class="color-box" style="background:#B19CD9;"></div> Approved</div>
  <div class="legend-item"><div class="color-box" style="background:#4A90E2;"></div> Booked</div>
  <div class="legend-item"><div class="color-box" style="background:#28a745;"></div> Completed</div>
  <div class="legend-item"><div class="color-box" style="background:#FF6961;"></div> Cancelled/Declined</div>
  <div class="legend-item"><div class="color-box" style="background:#8FD19E;"></div> Rest Day</div>
</div>

<div class="search-container">
  <select id="yearSelect"></select>
  <select id="monthSelect"></select>
  <select id="daySelect"></select>
  <button id="goBtn">Go</button>
  
  <form id="searchForm" style="display:inline-block; margin-left: 15px;">
    <input type="text" id="patientSearch" placeholder="🔎 Search by patient name..." style="width:250px;">
    <button type="submit">Search</button>
  </form>
  
  <div id="patientList"></div>
</div>

<div id="calendar"></div>

<div id="appointmentListModal" class="modal">
  <div class="modal-content">
    <button class="close-btn" id="closeList">×</button>
    <h3 id="listModalTitle">Appointments for Selected Date</h3>
    <div id="appointmentsContainer"></div>
  </div>
</div>

<div id="appointmentDetailModal" class="modal">
  <div class="modal-content">
    <button class="close-btn" id="closeDetail">×</button>
    <h3>Appointment Details</h3>
    <div id="appointmentDetails"></div>
    <div id="actionButtons"></div>
  </div>
</div>

<div id="restDayModal" class="modal">
  <div class="modal-content" style="width: 350px;">
    <button class="close-btn" id="closeRest">×</button>
    <h3>Doctor's Rest Day</h3>
    <p id="restDayInfo" style="font-size:15px;color:#333;text-align:center;"></p>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  /* -------------------------------------------
     DOM Selectors
  ------------------------------------------- */
  const calendarEl = document.getElementById('calendar');
  const modalList = document.getElementById('appointmentListModal');
  const modalDetail = document.getElementById('appointmentDetailModal');
  const modalRest = document.getElementById('restDayModal');
  const appointmentsContainer = document.getElementById('appointmentsContainer');
  const detailsBox = document.getElementById('appointmentDetails');
  const restInfo = document.getElementById('restDayInfo');
  const actionButtons = document.getElementById('actionButtons');
  const listModalTitle = document.getElementById('listModalTitle');
  const searchInput = document.getElementById('patientSearch');
  const patientList = document.getElementById('patientList');
  const searchForm = document.getElementById('searchForm');
  
  // Date Selectors
  const yearSelect = document.getElementById('yearSelect');
  const monthSelect = document.getElementById('monthSelect');
  const daySelect = document.getElementById('daySelect');
  const goBtn = document.getElementById('goBtn');

  let selectedAppointment = null;
  let showAll = false; // Search toggle for show all / upcoming only

  /* -------------------------------------------
     FullCalendar Initialization
  ------------------------------------------- */
  const calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    events: '../calendar_function/fetch_appointments_secretary.php',
    
    // ADD CLASS FOR STATUS STYLING (UPDATED)
    eventDidMount: function(info) {
        // Normalize status to lowercase for CSS class: pending, approved, booked, completed, cancelled, declined, restday
        const status = (info.event.extendedProps.status || '').toLowerCase().replace(/\s/g, '');
        info.el.classList.add(`status-${status}`);
    },

    // FORMAT EVENT CONTENT TO SHOW STATUS AND COUNT
    eventContent: function(arg) {
      const status = arg.event.extendedProps.status;
      const count = arg.event.extendedProps.count; 

      let contentHtml = `<div style="font-weight:700; font-size:0.95rem; text-align:center;">`;

      if (status === 'Rest Day') {
        contentHtml += `${status}`;
      } else if (count > 0) {
        contentHtml += `${status}: ${count}`;
      } else {
        contentHtml += `${arg.event.title}`;
      }
      
      contentHtml += `</div>`;
      
      return { html: contentHtml };
    },

    dateClick: function(info) {
      // 1. Check for Rest Day
      fetch('../calendar_function/check_rest_day_secretary.php?date=' + info.dateStr)
        .then(res => res.json())
        .then(data => {
          if (data.isRestDay) {
            restInfo.innerHTML = `<strong>${info.dateStr}</strong> is marked as a Rest Day.<br><em>Reason:</em> ${data.reason || 'No reason provided.'}`;
            modalRest.classList.add('active');
          } else {
            // 2. Fetch appointments for the date
            fetchAppointments(info.dateStr);
          }
        })
        .catch(err => console.error('Rest Day Check Error:', err));
    }
  });
  calendar.render();

  /* -------------------------------------------
     Date Selectors Setup & Handler
  ------------------------------------------- */
  const currentYear = new Date().getFullYear();
  const currentMonth = new Date().getMonth() + 1;
  const currentDay = new Date().getDate();

  for (let y = currentYear - 2; y <= currentYear + 2; y++) {
    const opt = new Option(y, y);
    if (y === currentYear) opt.selected = true;
    yearSelect.appendChild(opt);
  }
  for (let m = 1; m <= 12; m++) {
    const monthStr = m.toString().padStart(2, '0');
    const monthName = new Date(2000, m - 1).toLocaleString('default', { month: 'long' });
    const opt = new Option(monthName, monthStr);
    if (m === currentMonth) opt.selected = true;
    monthSelect.appendChild(opt);
  }
  for (let d = 1; d <= 31; d++) {
    const dayStr = d.toString().padStart(2, '0');
    const opt = new Option(d, dayStr);
    if (d === currentDay) opt.selected = true;
    daySelect.appendChild(opt);
  }

  goBtn.addEventListener('click', () => {
    const y = yearSelect.value;
    const m = monthSelect.value;
    const d = daySelect.value;
    if (y && m && d) {
        const dateStr = `${y}-${m}-${d}`;
        calendar.gotoDate(dateStr);
        fetchAppointments(dateStr);
    } else {
        alert("Please select a valid date (Year, Month, and Day).");
    }
  });

  /* -------------------------------------------
     Search Feature (Patient Name)
  ------------------------------------------- */
  searchForm.addEventListener('submit', e => { 
    e.preventDefault(); 
    performSearch(searchInput.value.trim()); 
    patientList.style.display = 'none';
  });

  searchInput.addEventListener('keyup', function() {
    const query = this.value.trim();
    patientList.style.display = 'none';
    if (query.length < 2) return;
    
    fetch('../calendar_function/fetch_patients_list_secretary.php?query=' + encodeURIComponent(query))
      .then(res => res.json())
      .then(data => {
        patientList.innerHTML = '';
        if (data.status === 'success' && data.patients.length > 0) {
          data.patients.forEach(p => {
            const item = document.createElement('div');
            item.textContent = p.patient_name;
            item.onclick = () => {
              searchInput.value = p.patient_name;
              patientList.style.display = 'none';
              performSearch(p.patient_name);
            };
            patientList.appendChild(item);
          });
          patientList.style.display = 'block';
        }
      })
      .catch(err => console.error('Autocomplete Error:', err));
  });

  document.addEventListener('click', e => { 
    if (!e.target.closest('#patientSearch') && !e.target.closest('#patientList')) 
      patientList.style.display = 'none'; 
  });

  function performSearch(query) {
    if (!query) return;
    // NOTE: fetch_appointments_by_patient_secretary.php is being used here
    fetch(`../calendar_function/fetch_appointments_by_patient_secretary.php?search=${encodeURIComponent(query)}`)
      .then(res => res.json())
      .then(data => {
        appointmentsContainer.innerHTML = '';
        listModalTitle.textContent = `Appointments for "${query}"`;
        showAppointmentListModal(true); // Always show modal for search

        // Create or update toggle button
        let toggleBtn = document.getElementById('togglePastBtn');
        if (!toggleBtn) {
          toggleBtn = document.createElement('button');
          toggleBtn.id = 'togglePastBtn';
          toggleBtn.className = 'btn btn-close-modal'; // Reusing a modal close style
          toggleBtn.style.cssText = 'position:absolute; top:25px; right:60px; padding:6px 10px; font-size:14px;';
          document.querySelector('#appointmentListModal .modal-content').appendChild(toggleBtn);
          toggleBtn.onclick = () => {
            showAll = !showAll;
            performSearch(query); // Re-run search with new toggle state
          };
        }
        toggleBtn.textContent = showAll ? 'Upcoming' : 'Show All';

        if (data.status === 'success' && data.appointments) {
          const today = new Date().toISOString().split('T')[0];
          const filtered = showAll ? data.appointments :
            data.appointments.filter(a => a.appointment_date >= today);

          if (filtered.length === 0) {
            appointmentsContainer.innerHTML = `<p style="text-align:center;color:#777;padding:15px;">No ${showAll ? '' : 'upcoming '}appointments found for "${query}".</p>`;
            return;
          }

          filtered.forEach(a => {
            appendAppointmentCard(a);
          });
        } else {
          appointmentsContainer.innerHTML = `<p style="text-align:center;color:#777;padding:15px;">No appointments found for "${query}".</p>`;
        }
      })
      .catch(err => console.error('Search Error:', err));
  }

  /* -------------------------------------------
     Appointment List/Detail Functions
  ------------------------------------------- */
  function fetchAppointments(date) {
    // NOTE: fetch_appointments_by_date_secretary.php is being used here
    fetch('../calendar_function/fetch_appointments_by_date_secretary.php?date=' + date)
      .then(res => res.json())
      .then(data => {
        if (data.status === 'empty') { alert('No appointments found for this date.'); return; }
        
        // Prepare list modal for date view
        appointmentsContainer.innerHTML = '';
        listModalTitle.textContent = `Appointments for ${date}`;
        showAppointmentListModal(true);
        
        // Remove search toggle button if it exists
        const toggleBtn = document.getElementById('togglePastBtn');
        if (toggleBtn) toggleBtn.remove(); 

        data.appointments.forEach(a => {
          appendAppointmentCard(a);
        });

      }).catch(err => console.error('Fetch Appointments Error:', err));
  }

  function appendAppointmentCard(a) {
    const card = document.createElement('div');
    card.className = 'appointment-card';
    const isPast = new Date(a.appointment_date) < new Date(new Date().toDateString());
    let statusColor;
    
    // UPDATED SWITCH STATEMENT
    switch (a.status.toLowerCase()) {
        // case 'pending':
        //     statusColor = '#FFD966'; // Yellow
        //     break;
        case 'approved':
            statusColor = '#B19CD9'; // Light Purple
            break;
        case 'booked':
            statusColor = '#4A90E2'; // Blue
            break;
        case 'completed':
            statusColor = '#28a745'; // Green
            break;
        case 'cancelled':
        case 'declined':
            statusColor = '#FF6961'; // Reddish
            break;
        default:
            statusColor = 'gray';
    }
    
    card.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div><strong>${a.patient_name}</strong> (${a.service})</div>
        ${isPast ? '<span style="color:gray;font-size:13px;font-style:italic;">(Past)</span>' : ''}
      </div>
      <div style="font-size:14px; margin-top: 5px;">${a.appointment_date} at ${a.appointment_time}</div>
      <div style="color:${statusColor}; font-weight: 600; margin-top: 5px;">
        Status: ${a.status}
      </div>`;
    card.onclick = () => showAppointmentDetails(a);
    appointmentsContainer.appendChild(card);
  }


  function showAppointmentDetails(a) {
    selectedAppointment = a;
    modalList.classList.remove('active');
    modalDetail.classList.add('active');
    
    // UPDATED SWITCH STATEMENT for color in modal
    let statusColor;
    switch (a.status.toLowerCase()) {
        //case 'pending': statusColor = '#FFD966'; break;
        case 'approved': statusColor = '#B19CD9'; break;
        case 'booked': statusColor = '#4A90E2'; break;
        case 'completed': statusColor = '#28a745'; break;
        case 'cancelled':
        case 'declined': statusColor = '#FF6961'; break;
        default: statusColor = 'gray';
    }
    
    // Patient details are now correctly coming from the PHP files
    detailsBox.innerHTML = `
      <div class="info-row"><span class="info-label">Patient Name:</span><span class="info-value">${a.patient_name}</span></div>
      <div class="info-row"><span class="info-label">Email:</span><span class="info-value">${a.email || '-'}</span></div>
      <div class="info-row"><span class="info-label">Contact:</span><span class="info-value">${a.contact_number || '-'}</span></div>
      <div class="info-row"><span class="info-label">Gender:</span><span class="info-value">${a.gender || '-'}</span></div>
      <div class="info-row"><span class="info-label">Age:</span><span class="info-value">${a.age || '-'}</span></div>
      <div class="info-row"><span class="info-label">Service:</span><span class="info-value">${a.service}</span></div>
      <div class="info-row"><span class="info-label">Date:</span><span class="info-value">${a.appointment_date}</span></div>
      <div class="info-row"><span class="info-label">Time:</span><span class="info-value">${a.appointment_time}</span></div>
      <div class="info-row"><span class="info-label">Comments:</span><span class="info-value">${a.comments || '-'}</span></div>
      <div class="info-row"><span class="info-label">Status:</span><span class="info-value" style="color:${statusColor};">${a.status}</span></div>`;
    
    // Action Buttons
    actionButtons.innerHTML = '';
    const today = new Date().toISOString().split('T')[0];
    const isPast = a.appointment_date < today;
    const currentStatus = a.status.toLowerCase();

    if (!isPast) { 
        if (currentStatus === 'pending') {
             // Pending appointments can be approved or declined
             actionButtons.innerHTML = `
                <button class="btn btn-approve" id="approveBtn">Approve</button>
                <button class="btn btn-decline" id="declineBtn">Decline</button>`;
            document.getElementById('approveBtn').onclick = () => updateStatus('approved'); 
            document.getElementById('declineBtn').onclick = () => updateStatus('declined');
        } else if (currentStatus === 'approved' || currentStatus === 'booked') {
            // Approved or Booked appointments can be completed or cancelled
            actionButtons.innerHTML = `
                <button class="btn btn-complete" id="completeBtn">Mark as Completed</button>
                <button class="btn btn-cancel" id="cancelBtn">Cancel Appointment</button>`;
            document.getElementById('completeBtn').onclick = () => updateStatus('completed');
            document.getElementById('cancelBtn').onclick = () => updateStatus('cancelled');
        } else if (currentStatus === 'completed') {
             actionButtons.innerHTML = `<p style="color: #28a745; font-weight: 600;">✅ Appointment already completed.</p>`;
        } else if (currentStatus === 'cancelled' || currentStatus === 'declined') {
             actionButtons.innerHTML = `<p style="color: #FF6961; font-style: italic;">Appointment is ${a.status.toLowerCase()}.</p>`;
        }
    } else {
        // Option to mark past appointment as completed if not already
        if (currentStatus !== 'completed' && currentStatus !== 'cancelled' && currentStatus !== 'declined') {
            actionButtons.innerHTML = `<button class="btn btn-complete" id="completeBtn">Mark as Completed (Past)</button>`;
            document.getElementById('completeBtn').onclick = () => updateStatus('completed');
        } else {
            actionButtons.innerHTML = `<p style="color: gray; font-style: italic;">No actions available for this appointment.</p>`;
        }
    }
  }

  function updateStatus(status) {
    if (!selectedAppointment) return alert('No appointment selected.');
    
    // Get new time from the input field
    const newTimeInput = document.getElementById('newTime');
    const newTime = newTimeInput ? newTimeInput.value : '';

    const body = new URLSearchParams({ 
        appointment_id: selectedAppointment.appointment_id, 
        status: status.toLowerCase(), 
        new_time: newTime // Pass the HH:MM string
    });
    
    // This calls the updated update_appointment_status_secretary.php
    fetch('../calendar_function/update_appointment_status_secretary.php', { 
        method: 'POST', 
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
        body: body.toString() 
    })
      .then(res => res.json())
      .then(resp => { 
        if (resp.status === 'success') {
             alert(`✅ ${resp.message}`); 
        } else {
             alert(`❌ ${resp.message || 'Unable to update status.'}`); 
        }
        modalDetail.classList.remove('active'); 
        selectedAppointment = null; 
        calendar.refetchEvents(); // Refresh calendar to show changes
        
        // After an update, re-run the search if it was active
        const currentQuery = searchInput.value.trim();
        if (currentQuery) performSearch(currentQuery);
      })
      .catch(err => { 
        console.error('Update Error:', err); 
        alert('❌ Submission failed. Check console for details.'); 
      });
  }
  
  // Helper to control modal visibility
  function showAppointmentListModal(show) {
      if (show) modalList.classList.add('active');
      else modalList.classList.remove('active');
  }

  /* -------------------------------------------
     Modal Close Handlers
  ------------------------------------------- */
  document.getElementById('closeList').onclick = () => showAppointmentListModal(false);
  document.getElementById('closeDetail').onclick = () => modalDetail.classList.remove('active');
  document.getElementById('closeRest').onclick = () => modalRest.classList.remove('active');
});
</script>

</body>
</html>