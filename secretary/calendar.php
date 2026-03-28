<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Doctor Schedule Calendar</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      background-color: #f4f4f4;
    }

    .calendar-container {
      background-color: #fff;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
      width: 360px;
      position: relative;
    }

    .calendar-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .calendar-header h2 {
      margin: 0;
      font-size: 1.5em;
    }

    .calendar-header span {
      cursor: pointer;
      font-size: 1.2em;
      padding: 5px 10px;
      border-radius: 4px;
      transition: background-color 0.3s ease;
    }

    .calendar-header span:hover {
      background-color: #eee;
    }

    .calendar-table {
      width: 100%;
      border-collapse: collapse;
    }

    .calendar-table th,
    .calendar-table td {
      text-align: center;
      padding: 10px;
      border: 1px solid #eee;
      cursor: pointer;
      transition: 0.2s;
    }

    .calendar-table th {
      background-color: #f0f0f0;
    }

    .calendar-table td:hover {
      background-color: #dceeff;
    }

    /* Color codes */
    .status-pending {
      background-color: #fff3b0; /* yellow */
    }

    .status-rest {
      background-color: #b6f7b6; /* green */
    }

    .status-booked {
      background-color: #b6ccf7; /* blue */
    }

    .current-day {
      border: 2px solid #007bff;
    }

    /* Compact Legend */
    .legend {
      display: flex;
      justify-content: space-around;
      margin-top: 10px;
      font-size: 0.9em;
      color: #333;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .legend-color {
      width: 14px;
      height: 14px;
      border-radius: 3px;
      border: 1px solid #ccc;
    }

    .legend-pending { background-color: #fff3b0; }
    .legend-rest { background-color: #b6f7b6; }
    .legend-booked { background-color: #b6ccf7; }
    .legend-none { background-color: #fff; }

    /* Modal */
    .modal {
      display: none;
      position: fixed;
      top: 0; left: 0;
      width: 100%; height: 100%;
      background: rgba(0, 0, 0, 0.5);
      justify-content: center;
      align-items: center;
    }

    .modal-content {
      background: #fff;
      padding: 20px;
      border-radius: 8px;
      width: 320px;
      text-align: left;
      position: relative;
      max-height: 80vh;
      overflow-y: auto;
    }

    .close-btn {
      position: absolute;
      top: 10px;
      right: 10px;
      cursor: pointer;
      font-size: 18px;
      color: #333;
    }

    .close-btn:hover {
      color: red;
    }

    .appointment {
      background-color: #e8f1ff;
      border-left: 4px solid #007bff;
      padding: 8px;
      margin-bottom: 10px;
      border-radius: 4px;
    }

    .appointment strong {
      color: #007bff;
    }

    .no-appointments {
      text-align: center;
      color: #888;
    }
  </style>
</head>
<body>
  <div class="calendar-container">
    <div class="calendar-header">
      <span class="prev-month">&lt;</span>
      <h2 id="current-month-year">October 2025</h2>
      <span class="next-month">&gt;</span>
    </div>

    <table class="calendar-table">
      <thead>
        <tr>
          <th>Sun</th>
          <th>Mon</th>
          <th>Tue</th>
          <th>Wed</th>
          <th>Thu</th>
          <th>Fri</th>
          <th>Sat</th>
        </tr>
      </thead>
      <tbody id="calendar-body">
        <!-- Calendar days generated dynamically -->
      </tbody>
    </table>

    <!-- Compact single-line legend -->
    <div class="legend">
      <div class="legend-item"><div class="legend-color legend-pending"></div>Pending</div>
      <div class="legend-item"><div class="legend-color legend-rest"></div>Rest</div>
      <div class="legend-item"><div class="legend-color legend-booked"></div>Booked</div>
      <div class="legend-item"><div class="legend-color legend-none"></div>None</div>
    </div>
  </div>

  <!-- Modal -->
  <div id="modal" class="modal">
    <div class="modal-content">
      <span class="close-btn">&times;</span>
      <h3 id="modal-date"></h3>
      <div id="modal-info"></div>
    </div>
  </div>

  <script>
    const calendarBody = document.getElementById('calendar-body');
    const currentMonthYear = document.getElementById('current-month-year');
    const prevMonthBtn = document.querySelector('.prev-month');
    const nextMonthBtn = document.querySelector('.next-month');
    const modal = document.getElementById('modal');
    const modalDate = document.getElementById('modal-date');
    const modalInfo = document.getElementById('modal-info');
    const closeBtn = document.querySelector('.close-btn');

    let date = new Date();
    let currentYear = date.getFullYear();
    let currentMonth = date.getMonth();

    const months = [
      "January", "February", "March", "April", "May", "June",
      "July", "August", "September", "October", "November", "December"
    ];

    // Example schedule data
    const statusMap = {
      "2025-10-05": {
        status: "rest",
        info: "Doctor's Rest Day",
        appointments: []
      },
      "2025-10-10": {
        status: "booked",
        info: "3 Appointments Scheduled",
        appointments: [
          { time: "8:00 AM - 9:00 AM", patient: "John Doe" },
          { time: "10:00 AM - 12:00 PM", patient: "Jane Smith" },
          { time: "2:00 PM - 3:30 PM", patient: "Michael Cruz" }
        ]
      },
      "2025-10-15": {
        status: "pending",
        info: "Awaiting patient confirmations",
        appointments: [
          { time: "9:00 AM - 10:00 AM", patient: "Maria Lopez" }
        ]
      },
      "2025-10-22": {
        status: "booked",
        info: "Routine Checkups",
        appointments: [
          { time: "9:00 AM - 11:00 AM", patient: "Emily Santos" },
          { time: "1:00 PM - 2:00 PM", patient: "Robert Tan" }
        ]
      }
    };

    function renderCalendar() {
      calendarBody.innerHTML = '';
      currentMonthYear.textContent = `${months[currentMonth]} ${currentYear}`;

      const firstDayOfMonth = new Date(currentYear, currentMonth, 1).getDay();
      const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();

      let row = document.createElement('tr');
      for (let i = 0; i < firstDayOfMonth; i++) {
        row.appendChild(document.createElement('td'));
      }

      for (let day = 1; day <= daysInMonth; day++) {
        if (row.children.length === 7) {
          calendarBody.appendChild(row);
          row = document.createElement('tr');
        }

        const cell = document.createElement('td');
        cell.textContent = day;

        const dateKey = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const today = new Date();

        if (statusMap[dateKey]) {
          const { status } = statusMap[dateKey];
          cell.classList.add(`status-${status}`);
        }

        if (day === today.getDate() && currentMonth === today.getMonth() && currentYear === today.getFullYear()) {
          cell.classList.add('current-day');
        }

        cell.addEventListener('click', () => {
          showDetails(dateKey, day);
        });

        row.appendChild(cell);
      }

      calendarBody.appendChild(row);
    }

    function showDetails(dateKey, day) {
      modal.style.display = 'flex';
      modalDate.textContent = `${months[currentMonth]} ${day}, ${currentYear}`;
      modalInfo.innerHTML = '';

      if (statusMap[dateKey]) {
        const { info, appointments, status } = statusMap[dateKey];
        const statusText = `<p><strong>Status:</strong> ${status.charAt(0).toUpperCase() + status.slice(1)}</p>`;
        modalInfo.innerHTML += statusText;

        if (appointments.length > 0) {
          appointments.forEach(app => {
            const div = document.createElement('div');
            div.classList.add('appointment');
            div.innerHTML = `<strong>${app.time}</strong><br>${app.patient}`;
            modalInfo.appendChild(div);
          });
        } else {
          modalInfo.innerHTML += `<p class="no-appointments">No appointments scheduled.</p>`;
        }
      } else {
        modalInfo.innerHTML = `<p class="no-appointments">No appointments yet for this date.</p>`;
      }
    }

    closeBtn.addEventListener('click', () => modal.style.display = 'none');
    window.addEventListener('click', e => {
      if (e.target === modal) modal.style.display = 'none';
    });

    prevMonthBtn.addEventListener('click', () => {
      currentMonth--;
      if (currentMonth < 0) {
        currentMonth = 11;
        currentYear--;
      }
      renderCalendar();
    });

    nextMonthBtn.addEventListener('click', () => {
      currentMonth++;
      if (currentMonth > 11) {
        currentMonth = 0;
        currentYear++;
      }
      renderCalendar();
    });

    renderCalendar();
  </script>
</body>
</html>
