<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>DentiTrack Logins</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- Font Awesome CDN -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <!-- Styles -->
  <style>
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      margin: 0;
      padding: 0;
      background-color: #eef2f5;
    }

    #logins {
      padding: 60px 20px;
      text-align: center;
    }

    .section-title {
      font-size: 2.5rem;
      color: #333;
      margin-bottom: 40px;
    }

    .login-options {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 30px;
    }

    .login-box {
      background-color: #ffffff;
      border-radius: 20px;
      padding: 30px 20px;
      width: 230px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .login-box:hover {
      transform: translateY(-8px);
      box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
    }

    .login-icon {
      color: #2d89ef;
      margin-bottom: 20px;
    }

    .login-box h3 {
      font-size: 1.2rem;
      margin-bottom: 15px;
      color: #333;
    }

    .login-btn {
      display: inline-block;
      padding: 10px 20px;
      background-color: #2d89ef;
      color: #ffffff;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 600;
      transition: background-color 0.3s ease;
    }

    .login-btn:hover {
      background-color: #226ec1;
    }

    @media (max-width: 768px) {
      .login-options {
        flex-direction: column;
        align-items: center;
      }
    }
  </style>
</head>
<body>

  <section id="logins">
    <h2 class="section-title">Logins</h2>
    <div class="login-options">

      <div class="login-box">
        <i class="fas fa-user-injured fa-4x login-icon"></i>
        <h3>Patient Login</h3>
        <a href="patient/patient_login.php" class="login-btn">Click Here</a>
      </div>

      <div class="login-box">
        <i class="fas fa-user-md fa-4x login-icon"></i>
        <h3>Doctor Login</h3>
        <a href="doctor/doctor_login.php" class="login-btn">Click Here</a>
      </div>

      <div class="login-box">
        <i class="fas fa-user-nurse fa-4x login-icon"></i>
        <h3>Secretary Login</h3>
        <a href="secretary/secretary_login.php" class="login-btn">Click Here</a>
      </div>

      <div class="login-box">
        <i class="fas fa-user-shield fa-4x login-icon"></i>
        <h3>Admin Login</h3>
        <a href="admin/admin_login.php" class="login-btn">Click Here</a>
      </div>

    </div>
  </section>

</body>
</html>
