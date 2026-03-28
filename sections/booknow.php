<section id="book">
  <div class="book-form-container">
    <h2 class="section-title">Book an Appointment</h2>
    <form class="book-form" action="book_process.php" method="POST">
      <div class="form-group">
        <label for="fullname">Full Name</label>
        <input type="text" id="fullname" name="fullname" required>
      </div>

      <div class="form-group">
        <label for="contact">Contact Number</label>
        <input type="text" id="contact" name="contact" required>
      </div>

      <div class="form-group">
        <label for="service">Service</label>
        <select id="service" name="service" required>
          <option value="">Select a Service</option>
          <option value="Cleaning">Dental Cleaning</option>
          <option value="Extraction">Tooth Extraction</option>
          <option value="Braces">Braces</option>
          <option value="Whitening">Teeth Whitening</option>
        </select>
      </div>

      <div class="form-group">
        <label for="date">Preferred Date</label>
        <input type="date" id="date" name="date" required>
      </div>

      <div class="form-group">
        <label for="message">Additional Notes</label>
        <textarea id="message" name="message" placeholder="Any concerns or preferences?"></textarea>
      </div>

      <button type="submit" class="btn-book">Submit Booking
