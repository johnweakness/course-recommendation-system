<?php include 'config.php'; ?>
<!DOCTYPE html>
<html>
<head><title>Admin - All Recommendations</title>
<style>
  table { width: 100%; border-collapse: collapse; margin: 20px 0; }
  th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
  th { background: #f0f0f0; }
</style>
</head>
<body>
<h2>All Student Recommendations</h2>
<table>
  <tr>
    <th>ID</th>
    <th>1st Choice</th>
    <th>2nd Choice</th>
    <th>3rd Choice</th>
    <th>Scores</th>
    <th>Submitted At</th>
  </tr>
  <?php
  $result = $mysqli->query("SELECT * FROM recommendations ORDER BY submitted_at DESC");
  while ($row = $result->fetch_assoc()) {
    echo "<tr>
      <td>{$row['id']}</td>
      <td>{$row['first_choice']}</td>
      <td>{$row['second_choice']}</td>
      <td>{$row['third_choice']}</td>
      <td>{$row['score1']} / {$row['score2']} / {$row['score3']}</td>
      <td>{$row['submitted_at']}</td>
    </tr>";
  }
  ?>
</table>
</body>
</html>