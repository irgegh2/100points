<?php
// Общая сборка аналитики одного ученика. Используется админкой и недельными письмами.

function sa_local_date(?string $value): string {
  if (!$value) return '';
  try {
    $dt = new DateTime($value, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Europe/Moscow'));
    return $dt->format('Y-m-d');
  } catch (Throwable $e) {
    return substr($value, 0, 10);
  }
}

function sa_percent(int $score, int $max): ?int {
  return $max > 0 ? (int)round($score * 100 / $max) : null;
}

function sa_report_setting(PDO $pdo, int $uid): array {
  $st = $pdo->prepare("SELECT enabled, recipients, weekday, sender_email, sender_name, last_sent_week
                       FROM student_report_settings WHERE user_id = ? LIMIT 1");
  $st->execute([$uid]);
  $row = $st->fetch() ?: [];
  $recipients = json_decode((string)($row['recipients'] ?? ''), true);
  if (!is_array($recipients)) $recipients = [];
  return [
    'enabled' => !empty($row['enabled']),
    'recipients' => array_values($recipients),
    'weekday' => max(1, min(7, (int)($row['weekday'] ?? 1))),
    'sender_email' => (string)($row['sender_email'] ?? 'info@100-points.ru'),
    'sender_name' => (string)($row['sender_name'] ?? '100-балльный репетитор'),
    'last_sent_week' => (string)($row['last_sent_week'] ?? ''),
  ];
}

function student_analytics(PDO $pdo, int $uid, array $options = []): array {
  $days = (int)($options['days'] ?? 90);
  if (!in_array($days, [7, 30, 90, 365, 0], true)) $days = 90;
  $courseFilter = max(0, (int)($options['course_id'] ?? 0));
  $today = date('Y-m-d');

  $u = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.phone, u.role, u.created_at,
            p.avatar, p.birthdate, p.nickname_color,
            w.crystals, w.lives, w.streak, w.streak_best, w.last_active, w.week_start
     FROM users u
     LEFT JOIN profiles p ON p.user_id = u.id
     LEFT JOIN wallets w ON w.user_id = u.id
     WHERE u.id = ? LIMIT 1"
  );
  $u->execute([$uid]);
  $user = $u->fetch();
  if (!$user) throw new RuntimeException('Ученик не найден');

  $accountStart = substr((string)$user['created_at'], 0, 10) ?: $today;
  $since = $days > 0 ? date('Y-m-d', strtotime("-$days days")) : $accountStart;
  // Для дневного графика не строим тысячи пустых точек: «за всё время» показывает последний год,
  // а помесячная таблица ниже остаётся полной.
  $chartSince = $days === 0 ? max($accountStart, date('Y-m-d', strtotime('-365 days'))) : $since;

  $goalsSt = $pdo->prepare("SELECT subject, target_score, hours_per_week FROM profile_goals WHERE user_id = ? ORDER BY subject");
  $goalsSt->execute([$uid]);
  $goals = array_map(fn($g) => [
    'subject' => $g['subject'],
    'target_score' => $g['target_score'] !== null ? (int)$g['target_score'] : null,
    'hours_per_week' => $g['hours_per_week'] !== null ? (int)$g['hours_per_week'] : null,
  ], $goalsSt->fetchAll());

  $enr = $pdo->prepare(
    "SELECT e.course_id, e.source, e.expires_at, e.created_at,
            c.title, c.type, c.color AS course_color,
            s.name AS subject, s.color AS subject_color
     FROM enrollments e
     JOIN courses c ON c.id = e.course_id
     JOIN subjects s ON s.id = c.subject_id
     WHERE e.user_id = ? ORDER BY s.position, c.position, c.id"
  );
  $enr->execute([$uid]);
  $courses = [];
  foreach ($enr as $row) {
    $cid = (int)$row['course_id'];
    $courses[$cid] = [
      'id' => $cid, 'title' => $row['title'], 'type' => $row['type'],
      'subject' => $row['subject'], 'color' => $row['subject_color'] ?: ($row['course_color'] ?: '#3f9bff'),
      'source' => $row['source'], 'expires_at' => $row['expires_at'],
      'active' => !$row['expires_at'] || $row['expires_at'] >= $today,
      'lessons_total' => 0, 'lessons_done' => 0,
      'homeworks_total' => 0, 'homeworks_done' => 0,
      'mocks_total' => 0, 'mocks_done' => 0,
      'average_score' => null, 'last_activity' => '',
    ];
  }

  $lessonSt = $pdo->prepare(
    "SELECT b.course_id, l.id, lp.done_at
     FROM lessons l JOIN blocks b ON b.id = l.block_id
     JOIN enrollments e ON e.course_id = b.course_id AND e.user_id = ?
     LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = ?"
  );
  $lessonSt->execute([$uid, $uid]);
  $lessonEvents = [];
  foreach ($lessonSt as $row) {
    $cid = (int)$row['course_id'];
    if (!isset($courses[$cid])) continue;
    $courses[$cid]['lessons_total']++;
    if ($row['done_at']) {
      $courses[$cid]['lessons_done']++;
      $date = sa_local_date($row['done_at']);
      $lessonEvents[] = ['date' => $date, 'course_id' => $cid];
      if ($date > $courses[$cid]['last_activity']) $courses[$cid]['last_activity'] = $date;
    }
  }

  $hwRows = $pdo->prepare(
    "SELECT hw.id, b.course_id, hw.deadline
     FROM homeworks hw JOIN lessons l ON l.id = hw.lesson_id
     JOIN blocks b ON b.id = l.block_id
     JOIN enrollments e ON e.course_id = b.course_id AND e.user_id = ?"
  );
  $hwRows->execute([$uid]);
  $homeworkCourse = [];
  $homeworkDeadline = [];
  foreach ($hwRows as $row) {
    $hid = (int)$row['id']; $cid = (int)$row['course_id'];
    $homeworkCourse[$hid] = $cid; $homeworkDeadline[$hid] = (string)$row['deadline'];
    if (isset($courses[$cid])) $courses[$cid]['homeworks_total']++;
  }

  $mockRows = $pdo->prepare(
    "SELECT m.id, m.course_id FROM mock_exams m
     JOIN enrollments e ON e.course_id = m.course_id AND e.user_id = ?"
  );
  $mockRows->execute([$uid]);
  $mockCourse = [];
  foreach ($mockRows as $row) {
    $mid = (int)$row['id']; $cid = (int)$row['course_id']; $mockCourse[$mid] = $cid;
    if (isset($courses[$cid])) $courses[$cid]['mocks_total']++;
  }

  $subSt = $pdo->prepare(
    "SELECT sub.id, sub.homework_id, sub.mock_id, sub.status, sub.score, sub.max_score,
            sub.created_at, sub.graded_at,
            hw.title AS homework_title, hw.level, hw.deadline, l.title AS lesson_title,
            mk.title AS mock_title, mk.month AS mock_month,
            COALESCE(b.course_id, mk.course_id) AS course_id,
            c.title AS course, s.name AS subject, s.color
     FROM submissions sub
     LEFT JOIN homeworks hw ON hw.id = sub.homework_id
     LEFT JOIN lessons l ON l.id = hw.lesson_id
     LEFT JOIN blocks b ON b.id = l.block_id
     LEFT JOIN mock_exams mk ON mk.id = sub.mock_id
     LEFT JOIN courses c ON c.id = COALESCE(b.course_id, mk.course_id)
     LEFT JOIN subjects s ON s.id = c.subject_id
     WHERE sub.user_id = ? ORDER BY sub.id DESC"
  );
  $subSt->execute([$uid]);
  $allSubmissions = [];
  $filteredSubmissions = [];
  $latestByWork = [];
  foreach ($subSt as $row) {
    $cid = (int)($row['course_id'] ?? 0);
    $kind = $row['mock_id'] !== null ? 'mock' : 'homework';
    $refId = (int)($kind === 'mock' ? $row['mock_id'] : $row['homework_id']);
    $date = sa_local_date($row['created_at']);
    $item = [
      'id' => (int)$row['id'], 'kind' => $kind, 'ref_id' => $refId,
      'title' => $kind === 'mock' ? $row['mock_title'] : $row['homework_title'],
      'lesson' => $row['lesson_title'], 'level' => $row['level'] ?: 'normal',
      'course_id' => $cid, 'course' => $row['course'], 'subject' => $row['subject'],
      'color' => $row['color'] ?: '#3f9bff', 'status' => $row['status'],
      'score' => (int)$row['score'], 'max_score' => (int)$row['max_score'],
      'percent' => sa_percent((int)$row['score'], (int)$row['max_score']),
      'created_at' => $row['created_at'], 'date' => $date, 'deadline' => $row['deadline'],
    ];
    $allSubmissions[] = $item;
    $key = $kind . ':' . $refId;
    if (!isset($latestByWork[$key])) $latestByWork[$key] = $item;
    if ($date >= $since && (!$courseFilter || $cid === $courseFilter)) $filteredSubmissions[] = $item;
  }

  $coursePercents = [];
  foreach ($latestByWork as $item) {
    $cid = $item['course_id'];
    if (!isset($courses[$cid])) continue;
    if ($item['kind'] === 'homework') $courses[$cid]['homeworks_done']++;
    else $courses[$cid]['mocks_done']++;
    if ($item['percent'] !== null) $coursePercents[$cid][] = $item['percent'];
    if ($item['date'] > $courses[$cid]['last_activity']) $courses[$cid]['last_activity'] = $item['date'];
  }
  foreach ($coursePercents as $cid => $values) $courses[$cid]['average_score'] = (int)round(array_sum($values) / count($values));

  $series = [];
  for ($d = $chartSince; $d <= $today; $d = date('Y-m-d', strtotime("$d +1 day"))) {
    $series[$d] = ['date' => $d, 'visits' => 0, 'submissions' => 0, 'lessons' => 0, 'trainer' => 0, 'total' => 0];
  }
  $activeDates = [];
  $recent = [];

  $visitSt = $pdo->prepare("SELECT created_at FROM coin_log WHERE user_id = ? AND ref_type = 'streak' ORDER BY id DESC");
  $visitSt->execute([$uid]);
  foreach ($visitSt as $row) {
    $date = sa_local_date($row['created_at']);
    // Вход на платформу нельзя честно приписать конкретному курсу.
    if ($date < $since || $courseFilter) continue;
    $activeDates[$date] = true;
    if (isset($series[$date])) $series[$date]['visits'] = 1;
  }
  foreach ($filteredSubmissions as $item) {
    $date = $item['date']; $activeDates[$date] = true;
    if (isset($series[$date])) $series[$date]['submissions']++;
    $recent[] = ['date' => $date, 'type' => $item['kind'], 'title' => $item['title'], 'meta' => ($item['percent'] ?? 0) . '%'];
  }
  foreach ($lessonEvents as $event) {
    if ($event['date'] < $since || ($courseFilter && $event['course_id'] !== $courseFilter)) continue;
    $activeDates[$event['date']] = true;
    if (isset($series[$event['date']])) $series[$event['date']]['lessons']++;
  }

  $trainerSt = $pdo->prepare(
    "SELECT ta.correct, ta.created_at, tt.subject_id, s.name AS subject
     FROM trainer_attempts ta JOIN trainer_tasks tt ON tt.id = ta.task_id
     JOIN subjects s ON s.id = tt.subject_id WHERE ta.user_id = ? ORDER BY ta.id DESC"
  );
  $trainerSt->execute([$uid]);
  $trainerAttempts = 0; $trainerCorrect = 0;
  foreach ($trainerSt as $row) {
    $trainerAttempts++; if ((int)$row['correct'] === 1) $trainerCorrect++;
    $date = sa_local_date($row['created_at']);
    if ($date < $since || $courseFilter) continue;
    $activeDates[$date] = true;
    if (isset($series[$date])) $series[$date]['trainer']++;
  }
  foreach ($series as &$point) $point['total'] = $point['visits'] + $point['submissions'] + $point['lessons'] + $point['trainer'];
  unset($point);

  $subjects = [];
  $months = [];
  $scoreTimeline = [];
  foreach (array_reverse($filteredSubmissions) as $item) {
    if ($item['percent'] === null) continue;
    $subject = $item['subject'] ?: 'Без предмета';
    if (!isset($subjects[$subject])) $subjects[$subject] = ['subject' => $subject, 'color' => $item['color'], 'attempts' => 0, 'score_sum' => 0, 'average' => 0, 'best' => 0, 'weak' => 100];
    $subjects[$subject]['attempts']++; $subjects[$subject]['score_sum'] += $item['percent'];
    $subjects[$subject]['best'] = max($subjects[$subject]['best'], $item['percent']);
    $subjects[$subject]['weak'] = min($subjects[$subject]['weak'], $item['percent']);
    $month = substr($item['date'], 0, 7);
    if (!isset($months[$month])) $months[$month] = ['month' => $month, 'attempts' => 0, 'score_sum' => 0, 'average' => 0];
    $months[$month]['attempts']++; $months[$month]['score_sum'] += $item['percent'];
    $scoreTimeline[] = ['date' => $item['date'], 'percent' => $item['percent'], 'title' => $item['title'], 'kind' => $item['kind'], 'submission_id' => $item['id']];
  }
  foreach ($subjects as &$subject) { $subject['average'] = (int)round($subject['score_sum'] / max(1, $subject['attempts'])); unset($subject['score_sum']); }
  unset($subject);
  foreach ($months as &$month) { $month['average'] = (int)round($month['score_sum'] / max(1, $month['attempts'])); unset($month['score_sum']); }
  unset($month);

  $overdueSt = $pdo->prepare(
    "SELECT hw.id, hw.title, hw.deadline, b.course_id, c.title AS course, s.name AS subject
     FROM homeworks hw JOIN lessons l ON l.id = hw.lesson_id JOIN blocks b ON b.id = l.block_id
     JOIN courses c ON c.id = b.course_id JOIN subjects s ON s.id = c.subject_id
     JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
     WHERE hw.deadline IS NOT NULL AND hw.deadline <> '' AND substr(hw.deadline,1,10) < ?
       AND NOT EXISTS (SELECT 1 FROM submissions sub WHERE sub.user_id = ? AND sub.homework_id = hw.id)
     ORDER BY hw.deadline DESC LIMIT 30"
  );
  $overdueSt->execute([$uid, $today, $uid]);
  $overdue = [];
  foreach ($overdueSt as $row) {
    if ($courseFilter && (int)$row['course_id'] !== $courseFilter) continue;
    $overdue[] = ['id' => (int)$row['id'], 'title' => $row['title'], 'deadline' => $row['deadline'], 'course' => $row['course'], 'subject' => $row['subject']];
  }

  $scalar = function (string $sql, array $params = []) use ($pdo): int {
    $st = $pdo->prepare($sql); $st->execute($params); return (int)$st->fetchColumn();
  };
  $engagement = [
    'ai_chats' => $scalar("SELECT COUNT(*) FROM ai_chats WHERE user_id = ?", [$uid]),
    'ai_messages' => $scalar("SELECT COUNT(*) FROM ai_messages m JOIN ai_chats c ON c.id = m.chat_id WHERE c.user_id = ? AND m.role = 'user'", [$uid]),
    'bank_attempted' => $scalar("SELECT COUNT(*) FROM bank_progress WHERE user_id = ?", [$uid]),
    'bank_correct' => $scalar("SELECT COUNT(*) FROM bank_progress WHERE user_id = ? AND correct = 1", [$uid]),
    'trainer_attempted' => $trainerAttempts,
    'trainer_correct' => $trainerCorrect,
    'achievements' => $scalar("SELECT COUNT(*) FROM user_achievements WHERE user_id = ?", [$uid]),
    'purchases' => $scalar("SELECT COUNT(*) FROM purchases WHERE user_id = ?", [$uid]),
  ];

  $deliverySt = $pdo->prepare(
    "SELECT recipients, period_start, period_end, status, error, created_at
     FROM student_report_deliveries WHERE user_id = ? ORDER BY id DESC LIMIT 12"
  );
  $deliverySt->execute([$uid]);
  $deliveries = [];
  foreach ($deliverySt as $row) {
    $list = json_decode((string)$row['recipients'], true);
    $deliveries[] = [
      'recipients' => is_array($list) ? $list : [], 'period_start' => $row['period_start'],
      'period_end' => $row['period_end'], 'status' => $row['status'], 'error' => $row['error'],
      'created_at' => $row['created_at'],
    ];
  }

  $validScores = array_values(array_filter(array_map(fn($s) => $s['percent'], $filteredSubmissions), fn($v) => $v !== null));
  $summaryCourses = $courseFilter && isset($courses[$courseFilter]) ? [$courses[$courseFilter]] : array_values($courses);
  $summary = [
    'courses' => count($summaryCourses),
    'active_days' => count($activeDates),
    'submissions' => count($filteredSubmissions),
    'average_score' => $validScores ? (int)round(array_sum($validScores) / count($validScores)) : null,
    'best_score' => $validScores ? max($validScores) : null,
    'lessons_done' => array_sum(array_column($summaryCourses, 'lessons_done')),
    'lessons_total' => array_sum(array_column($summaryCourses, 'lessons_total')),
    'homeworks_done' => array_sum(array_column($summaryCourses, 'homeworks_done')),
    'homeworks_total' => array_sum(array_column($summaryCourses, 'homeworks_total')),
    'overdue' => count($overdue),
    'pending_review' => count(array_filter($filteredSubmissions, fn($s) => $s['status'] === 'review')),
    'crystals' => (int)($user['crystals'] ?? 0),
    'lives' => (int)($user['lives'] ?? 0),
    'streak' => (int)($user['streak'] ?? 0),
    'streak_best' => (int)($user['streak_best'] ?? 0),
    'last_active' => (string)($user['last_active'] ?? ''),
  ];

  usort($recent, fn($a, $b) => $b['date'] <=> $a['date']);
  return [
    'student' => [
      'id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email'], 'phone' => $user['phone'],
      'role' => $user['role'], 'created_at' => $user['created_at'], 'avatar' => $user['avatar'] ?: '',
      'birthdate' => $user['birthdate'] ?: '', 'nickname_color' => $user['nickname_color'] ?: '', 'goals' => $goals,
    ],
    'period' => ['days' => $days, 'since' => $since, 'until' => $today, 'course_id' => $courseFilter],
    'summary' => $summary,
    'courses' => array_values($courses),
    'activity' => array_values($series),
    'scores' => $scoreTimeline,
    'subjects' => array_values($subjects),
    'months' => array_values($months),
    'submissions' => array_slice($filteredSubmissions, 0, 300),
    'overdue' => $overdue,
    'engagement' => $engagement,
    'recent_activity' => array_slice($recent, 0, 20),
    'report_setting' => sa_report_setting($pdo, $uid),
    'report_deliveries' => $deliveries,
  ];
}

function sa_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function student_weekly_report_html(array $data): string {
  $s = $data['student']; $m = $data['summary']; $subjects = $data['subjects'];
  usort($subjects, fn($a, $b) => $b['average'] <=> $a['average']);
  $best = $subjects[0] ?? null; $weak = $subjects ? $subjects[count($subjects) - 1] : null;
  $rows = '';
  foreach (array_slice($data['submissions'], 0, 8) as $work) {
    $rows .= '<tr><td style="padding:9px 0;border-bottom:1px solid #edf0f5">' . sa_escape($work['title']) . '<br><small style="color:#7d8490">' . sa_escape($work['subject']) . '</small></td>'
      . '<td style="padding:9px 0;border-bottom:1px solid #edf0f5;text-align:right;font-weight:800">' . ($work['percent'] === null ? 'на проверке' : $work['percent'] . '%') . '</td></tr>';
  }
  if ($rows === '') $rows = '<tr><td style="padding:14px 0;color:#7d8490">За неделю работ не было</td></tr>';
  $avg = $m['average_score'] === null ? '—' : $m['average_score'] . '%';
  return '<!doctype html><html><body style="margin:0;background:#f3f5f8;font-family:Arial,sans-serif;color:#171a21">'
    . '<div style="max-width:680px;margin:0 auto;padding:28px 14px"><div style="background:#10131a;color:#fff;border-radius:18px 18px 0 0;padding:24px 28px">'
    . '<div style="font-size:12px;color:#ff9a3d;font-weight:800;letter-spacing:.08em;text-transform:uppercase">Еженедельный отчёт</div>'
    . '<h1 style="margin:8px 0 4px;font-size:26px">' . sa_escape($s['name'] ?: 'Ученик') . '</h1><div style="color:#aeb5c2">' . sa_escape($data['period']['since']) . ' — ' . sa_escape($data['period']['until']) . '</div></div>'
    . '<div style="background:#fff;padding:24px 28px;border-radius:0 0 18px 18px">'
    . '<table width="100%" cellspacing="0" style="margin-bottom:22px"><tr>'
    . '<td><div style="font-size:25px;font-weight:900">' . $avg . '</div><small style="color:#7d8490">средний результат</small></td>'
    . '<td><div style="font-size:25px;font-weight:900">' . (int)$m['submissions'] . '</div><small style="color:#7d8490">сдано работ</small></td>'
    . '<td><div style="font-size:25px;font-weight:900">' . (int)$m['active_days'] . '</div><small style="color:#7d8490">активных дней</small></td>'
    . '<td><div style="font-size:25px;font-weight:900;color:#d94a43">' . (int)$m['overdue'] . '</div><small style="color:#7d8490">просрочено</small></td></tr></table>'
    . ($best ? '<p><b>Лучше всего:</b> ' . sa_escape($best['subject']) . ' — ' . (int)$best['average'] . '%</p>' : '')
    . ($weak && $weak !== $best ? '<p><b>Стоит подтянуть:</b> ' . sa_escape($weak['subject']) . ' — ' . (int)$weak['average'] . '%</p>' : '')
    . '<h2 style="margin:24px 0 8px;font-size:18px">Работы за неделю</h2><table width="100%" cellspacing="0">' . $rows . '</table>'
    . '<p style="margin:24px 0 0;color:#7d8490;font-size:12px">Отчёт сформирован платформой «100-балльный репетитор».</p></div></div></body></html>';
}
