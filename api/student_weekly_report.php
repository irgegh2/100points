<?php
// Данные только за семь суток перед отправкой. Не смешивать с накопительной
// аналитикой кабинета: она считает все завершённые уроки и старые просрочки.

function wr_moscow_time(?string $utc): ?DateTimeImmutable {
  if (!$utc) return null;
  try {
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Moscow'));
  } catch (Throwable $e) {
    return null;
  }
}

function wr_deadline(?string $raw, int $extraDays): ?array {
  $raw = trim((string)$raw);
  if ($raw === '') return null;
  try {
    $moscow = new DateTimeZone('Europe/Moscow');
    $dateOnly = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1;
    $date = new DateTimeImmutable($dateOnly ? $raw . ' 00:00:00' : $raw, $moscow);
    if ($extraDays > 0) $date = $date->modify('+' . $extraDays . ' days');
    // Строка YYYY-MM-DD разрешает сдавать работу весь указанный день.
    $cutoff = $dateOnly ? $date->modify('+1 day') : $date;
    $label = $dateOnly ? $date->format('d.m.Y') . ' до 23:59' : $date->format('d.m.Y H:i');
    return ['cutoff' => $cutoff, 'label' => $label];
  } catch (Throwable $e) {
    return null;
  }
}

function wr_escape($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function student_weekly_report_data(PDO $pdo, int $uid, ?DateTimeImmutable $until = null): array {
  $moscow = new DateTimeZone('Europe/Moscow');
  $utc = new DateTimeZone('UTC');
  $until = ($until ?: new DateTimeImmutable('now', $moscow))->setTimezone($moscow);
  $since = $until->modify('-7 days');
  $startUtc = $since->setTimezone($utc)->format('Y-m-d H:i:s');
  $endUtc = $until->setTimezone($utc)->format('Y-m-d H:i:s');

  $userSt = $pdo->prepare('SELECT id,name FROM users WHERE id=? LIMIT 1');
  $userSt->execute([$uid]);
  $student = $userSt->fetch();
  if (!$student) throw new RuntimeException('Ученик не найден');

  $subSt = $pdo->prepare(
    "SELECT sub.id,sub.homework_id,sub.mock_id,sub.status,sub.score,sub.max_score,sub.created_at,
            hw.title AS homework_title,l.title AS lesson_title,mk.title AS mock_title,
            c.title AS course_title,s.name AS subject
     FROM submissions sub
     LEFT JOIN homeworks hw ON hw.id=sub.homework_id
     LEFT JOIN lessons l ON l.id=hw.lesson_id
     LEFT JOIN blocks b ON b.id=l.block_id
     LEFT JOIN mock_exams mk ON mk.id=sub.mock_id
     LEFT JOIN courses c ON c.id=COALESCE(b.course_id,mk.course_id)
     LEFT JOIN subjects s ON s.id=c.subject_id
     WHERE sub.user_id=? AND sub.created_at>=? AND sub.created_at<?
     ORDER BY sub.created_at DESC,sub.id DESC"
  );
  $subSt->execute([$uid, $startUtc, $endUtc]);
  $worksByKey = [];
  $attempts = 0;
  $activeDays = [];
  foreach ($subSt as $row) {
    $kind = $row['mock_id'] === null ? 'homework' : 'mock';
    $id = (int)($kind === 'mock' ? $row['mock_id'] : $row['homework_id']);
    if ($id <= 0) continue;
    $attempts++;
    $key = $kind . ':' . $id;
    $when = wr_moscow_time($row['created_at']);
    if (!$when) continue;
    $activeDays[$when->format('Y-m-d')] = true;
    if (isset($worksByKey[$key])) {
      $worksByKey[$key]['attempts']++;
      continue;
    }
    $score = (int)$row['score'];
    $max = (int)$row['max_score'];
    $graded = $row['status'] === 'graded' && $max > 0;
    $worksByKey[$key] = [
      'kind' => $kind,
      'id' => $id,
      'title' => (string)($kind === 'mock' ? $row['mock_title'] : $row['homework_title']),
      'lesson' => (string)($row['lesson_title'] ?? ''),
      'course' => (string)($row['course_title'] ?? ''),
      'subject' => (string)($row['subject'] ?? 'Без предмета'),
      'date' => $when->format('d.m.Y H:i'),
      'status' => $row['status'],
      'score' => $score,
      'max_score' => $max,
      'percent' => $graded ? (int)round($score * 100 / $max) : null,
      'attempts' => 1,
    ];
  }
  $works = array_values($worksByKey);

  $lessonSt = $pdo->prepare(
    "SELECT l.title,c.title AS course,s.name AS subject,lp.done_at
     FROM lesson_progress lp
     JOIN lessons l ON l.id=lp.lesson_id
     JOIN blocks b ON b.id=l.block_id
     JOIN courses c ON c.id=b.course_id
     JOIN subjects s ON s.id=c.subject_id
     WHERE lp.user_id=? AND lp.done_at>=? AND lp.done_at<?
     ORDER BY lp.done_at DESC,lp.id DESC"
  );
  $lessonSt->execute([$uid, $startUtc, $endUtc]);
  $lessons = [];
  foreach ($lessonSt as $row) {
    $when = wr_moscow_time($row['done_at']);
    if (!$when) continue;
    $activeDays[$when->format('Y-m-d')] = true;
    $lessons[] = ['title' => $row['title'], 'course' => $row['course'], 'subject' => $row['subject'], 'date' => $when->format('d.m.Y H:i')];
  }

  $trainerSt = $pdo->prepare('SELECT correct,created_at FROM trainer_attempts WHERE user_id=? AND created_at>=? AND created_at<?');
  $trainerSt->execute([$uid, $startUtc, $endUtc]);
  $trainer = ['attempted' => 0, 'correct' => 0, 'checked' => 0];
  foreach ($trainerSt as $row) {
    $trainer['attempted']++;
    if ($row['correct'] !== null) {
      $trainer['checked']++;
      if ((int)$row['correct'] === 1) $trainer['correct']++;
    }
    $when = wr_moscow_time($row['created_at']);
    if ($when) $activeDays[$when->format('Y-m-d')] = true;
  }

  $visitSt = $pdo->prepare("SELECT created_at FROM coin_log WHERE user_id=? AND ref_type='streak' AND created_at>=? AND created_at<?");
  $visitSt->execute([$uid, $startUtc, $endUtc]);
  foreach ($visitSt as $row) {
    $when = wr_moscow_time($row['created_at']);
    if ($when) $activeDays[$when->format('Y-m-d')] = true;
  }

  // Просрочка считается по персональному сроку с учётом VIP и продлений.
  // В письмо попадают только сроки, истёкшие в выбранные семь суток.
  $vipSt = $pdo->prepare("SELECT 1 FROM purchases WHERE user_id=? AND item_name='VIP-статус навсегда' AND status='done' LIMIT 1");
  $vipSt->execute([$uid]);
  $vipDays = $vipSt->fetchColumn() ? 1 : 0;
  $extensionSt = $pdo->prepare('SELECT homework_id,COALESCE(SUM(extra_days),0) AS extra_days FROM deadline_extensions WHERE user_id=? GROUP BY homework_id');
  $extensionSt->execute([$uid]);
  $extensions = [];
  foreach ($extensionSt as $row) $extensions[(int)$row['homework_id']] = (int)$row['extra_days'];

  $firstSt = $pdo->prepare('SELECT homework_id,MIN(created_at) AS first_at FROM submissions WHERE user_id=? AND homework_id IS NOT NULL GROUP BY homework_id');
  $firstSt->execute([$uid]);
  $firstSubmission = [];
  foreach ($firstSt as $row) $firstSubmission[(int)$row['homework_id']] = wr_moscow_time($row['first_at']);

  $deadlineSt = $pdo->prepare(
    "SELECT hw.id,hw.title,hw.deadline,l.title AS lesson,c.title AS course,c.id AS course_id,s.name AS subject,
            e.created_at AS enrolled_at,e.expires_at
     FROM homeworks hw
     JOIN lessons l ON l.id=hw.lesson_id
     JOIN blocks b ON b.id=l.block_id
     JOIN courses c ON c.id=b.course_id
     JOIN subjects s ON s.id=c.subject_id
     JOIN enrollments e ON e.course_id=c.id AND e.user_id=?
     WHERE hw.deadline IS NOT NULL AND hw.deadline<>''"
  );
  $deadlineSt->execute([$uid]);
  $due = [];
  foreach ($deadlineSt as $row) {
    $id = (int)$row['id'];
    $deadline = wr_deadline($row['deadline'], $vipDays + ($extensions[$id] ?? 0));
    if (!$deadline) continue;
    $cutoff = $deadline['cutoff'];
    if ($cutoff < $since || $cutoff >= $until) continue;
    $enrolled = wr_moscow_time($row['enrolled_at']);
    if ($enrolled && $enrolled > $cutoff) continue;
    if ($row['expires_at'] && $row['expires_at'] < $cutoff->format('Y-m-d')) continue;
    $first = $firstSubmission[$id] ?? null;
    $state = !$first || $first >= $until ? 'missed' : ($first > $cutoff ? 'late' : 'on_time');
    $due[] = [
      'id' => $id, 'title' => $row['title'], 'lesson' => $row['lesson'],
      'course' => $row['course'], 'subject' => $row['subject'],
      'deadline' => $deadline['label'], 'cutoff' => $cutoff->format('Y-m-d H:i:s'),
      'state' => $state, 'submitted_at' => $first ? $first->format('d.m.Y H:i') : '',
    ];
  }
  usort($due, fn($a, $b) => strcmp($a['cutoff'], $b['cutoff']));
  $missed = array_values(array_filter($due, fn($work) => $work['state'] === 'missed'));
  $late = array_values(array_filter($due, fn($work) => $work['state'] === 'late'));
  $lateIds = array_fill_keys(array_column($late, 'id'), true);
  foreach ($works as &$work) if ($work['kind'] === 'homework' && isset($lateIds[$work['id']])) $work['late'] = true;
  unset($work);

  $subjects = [];
  $scores = [];
  $homeworkCount = 0;
  $mockCount = 0;
  $reviewCount = 0;
  foreach ($works as $work) {
    if ($work['kind'] === 'homework') $homeworkCount++; else $mockCount++;
    if ($work['status'] === 'review') $reviewCount++;
    $subject = $work['subject'];
    if (!isset($subjects[$subject])) $subjects[$subject] = ['subject' => $subject, 'homeworks' => 0, 'mocks' => 0, 'graded' => 0, 'score_sum' => 0, 'average' => null];
    if ($work['kind'] === 'homework') $subjects[$subject]['homeworks']++; else $subjects[$subject]['mocks']++;
    if ($work['percent'] !== null) {
      $scores[] = $work['percent'];
      $subjects[$subject]['graded']++;
      $subjects[$subject]['score_sum'] += $work['percent'];
    }
  }
  foreach ($subjects as &$subject) {
    if ($subject['graded']) $subject['average'] = (int)round($subject['score_sum'] / $subject['graded']);
    unset($subject['score_sum']);
  }
  unset($subject);
  ksort($subjects, SORT_LOCALE_STRING);
  ksort($activeDays);

  return [
    'student' => ['id' => (int)$student['id'], 'name' => $student['name']],
    'period' => [
      'since' => $since->format('Y-m-d H:i:s'), 'until' => $until->format('Y-m-d H:i:s'),
      'label' => $since->format('d.m.Y H:i') . ' — ' . $until->format('d.m.Y H:i'),
      'timezone' => 'МСК',
    ],
    'summary' => [
      'homeworks' => $homeworkCount, 'mocks' => $mockCount, 'attempts' => $attempts,
      'lessons' => count($lessons), 'active_days' => count($activeDays),
      'average_score' => $scores ? (int)round(array_sum($scores) / count($scores)) : null,
      'graded_works' => count($scores), 'pending_review' => $reviewCount,
      'deadlines' => count($due), 'missed' => count($missed), 'late' => count($late),
    ],
    'works' => $works, 'lessons' => $lessons, 'trainer' => $trainer,
    'subjects' => array_values($subjects), 'missed' => $missed, 'late' => $late,
    'active_dates' => array_keys($activeDays),
  ];
}

function student_weekly_report_html(array $data): string {
  $m = $data['summary'];
  $period = $data['period'];
  $section = static fn(string $title) => '<h2 style="font-size:18px;line-height:24px;margin:28px 0 10px;color:#151a24">' . wr_escape($title) . '</h2>';
  $empty = static fn(string $message) => '<p style="font-size:14px;color:#687486;margin:7px 0 0">' . wr_escape($message) . '</p>';
  $tableStart = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;line-height:20px">';
  $cell = static fn(string $content, bool $right = false) => '<td style="padding:10px 6px;border-bottom:1px solid #e7ebf0;vertical-align:top' . ($right ? ';text-align:right' : '') . '">' . $content . '</td>';

  $workRows = '';
  foreach ($data['works'] as $work) {
    $kind = $work['kind'] === 'mock' ? 'Пробник' : 'ДЗ';
    $result = $work['percent'] === null ? 'На проверке' : $work['score'] . '/' . $work['max_score'] . ' · ' . $work['percent'] . '%';
    $detail = wr_escape($work['subject'] . ' · ' . $work['course']);
    if ($work['lesson']) $detail .= '<br>' . wr_escape('Урок: ' . $work['lesson']);
    if ($work['attempts'] > 1) $detail .= '<br>' . wr_escape('Попыток за неделю: ' . $work['attempts']);
    if (!empty($work['late'])) $detail .= '<br><strong style="color:#bd4a2d">Сдано с опозданием</strong>';
    $workRows .= '<tr>' . $cell(wr_escape($work['date']) . '<br><span style="color:#687486">' . $kind . '</span>')
      . $cell('<strong>' . wr_escape($work['title']) . '</strong><br><span style="font-size:12px;color:#687486">' . $detail . '</span>')
      . $cell('<strong>' . wr_escape($result) . '</strong>', true) . '</tr>';
  }

  $missedRows = '';
  foreach ($data['missed'] as $work) {
    $missedRows .= '<tr>' . $cell('<strong>' . wr_escape($work['title']) . '</strong><br><span style="color:#687486;font-size:12px">' . wr_escape($work['subject'] . ' · ' . $work['lesson']) . '</span>')
      . $cell(wr_escape($work['deadline']), true) . '</tr>';
  }
  $lateRows = '';
  foreach ($data['late'] as $work) {
    $lateRows .= '<tr>' . $cell('<strong>' . wr_escape($work['title']) . '</strong><br><span style="color:#687486;font-size:12px">' . wr_escape($work['subject'] . ' · ' . $work['lesson']) . '</span>')
      . $cell('Срок: ' . wr_escape($work['deadline']) . '<br>Сдано: ' . wr_escape($work['submitted_at']), true) . '</tr>';
  }

  $lessonRows = '';
  foreach ($data['lessons'] as $lesson) {
    $lessonRows .= '<tr>' . $cell(wr_escape($lesson['date']))
      . $cell('<strong>' . wr_escape($lesson['title']) . '</strong><br><span style="color:#687486;font-size:12px">' . wr_escape($lesson['subject'] . ' · ' . $lesson['course']) . '</span>') . '</tr>';
  }
  $subjectRows = '';
  foreach ($data['subjects'] as $subject) {
    $description = $subject['homeworks'] . ' ДЗ · ' . $subject['mocks'] . ' пробников';
    $avg = $subject['average'] === null ? 'на проверке' : $subject['average'] . '% по ' . $subject['graded'] . ' проверенным работам';
    $subjectRows .= '<tr>' . $cell('<strong>' . wr_escape($subject['subject']) . '</strong>')
      . $cell(wr_escape($description . ' · ' . $avg), true) . '</tr>';
  }
  $trainer = $data['trainer'];
  $avg = $m['average_score'] === null ? '—' : $m['average_score'] . '%';
  $intro = $m['homeworks'] . ' ДЗ и ' . $m['mocks'] . ' пробников сдано; ' . $m['missed'] . ' новых несданных просрочек, ' . $m['late'] . ' работ сдано после срока.';

  return '<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
    . '<body style="margin:0;background:#f1f4f8;color:#151a24;font-family:Arial,Helvetica,sans-serif">'
    . '<div style="max-width:700px;margin:0 auto;padding:24px 12px">'
    . '<div style="background:#17202b;color:#fff;padding:26px 30px;border-radius:18px 18px 0 0">'
    . '<div style="font-size:12px;letter-spacing:.08em;color:#ff9b3d;font-weight:800">100-БАЛЛЬНЫЙ РЕПЕТИТОР · УЧЕБНЫЙ ОТЧЁТ</div>'
    . '<h1 style="font-size:27px;line-height:32px;margin:10px 0 8px">' . wr_escape($data['student']['name'] ?: 'Ученик') . '</h1>'
    . '<div style="font-size:14px;color:#c8d0db">За последние 7 суток: ' . wr_escape($period['label'] . ' ' . $period['timezone']) . '</div></div>'
    . '<div style="background:#fff;padding:25px 30px;border-radius:0 0 18px 18px">'
    . '<p style="font-size:15px;line-height:23px;margin:0 0 20px">' . wr_escape($intro) . '</p>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;border-radius:12px"><tr>'
    . '<td style="padding:15px 12px"><strong style="font-size:22px">' . (int)$m['homeworks'] . '</strong><br><span style="font-size:12px;color:#687486">ДЗ</span></td>'
    . '<td style="padding:15px 12px"><strong style="font-size:22px">' . (int)$m['mocks'] . '</strong><br><span style="font-size:12px;color:#687486">пробников</span></td>'
    . '<td style="padding:15px 12px"><strong style="font-size:22px">' . (int)$m['lessons'] . '</strong><br><span style="font-size:12px;color:#687486">уроков пройдено</span></td>'
    . '<td style="padding:15px 12px"><strong style="font-size:22px">' . (int)$m['active_days'] . '/7</strong><br><span style="font-size:12px;color:#687486">активных дней</span></td>'
    . '</tr></table>'
    . '<p style="font-size:13px;line-height:20px;color:#687486">Средний результат проверенных работ: <strong style="color:#151a24">' . wr_escape($avg)
    . '</strong> (' . (int)$m['graded_works'] . ' работ). На проверке: ' . (int)$m['pending_review']
    . '. Повторных попыток: ' . max(0, (int)$m['attempts'] - (int)$m['homeworks'] - (int)$m['mocks']) . '.</p>'
    . $section('Сданные работы за неделю')
    . ($workRows ? $tableStart . $workRows . '</table>' : $empty('За эти 7 суток ДЗ и пробники не сдавались.'))
    . $section('Новые просрочки за неделю')
    . ($missedRows ? $tableStart . $missedRows . '</table>' : $empty('Сроков, истекших без сдачи за эти 7 суток, нет.'))
    . ($lateRows ? $section('Сдано после срока') . $tableStart . $lateRows . '</table>' : '')
    . '<p style="font-size:12px;color:#687486">Из ' . (int)$m['deadlines'] . ' сроков, истекших за неделю, ' . (int)$m['missed']
    . ' не сданы, ' . (int)$m['late'] . ' сданы поздно. Ранее возникшие просрочки здесь не повторяются.</p>'
    . $section('Пройденные уроки')
    . ($lessonRows ? $tableStart . $lessonRows . '</table>' : $empty('За эти 7 суток уроки не отмечались пройденными.'))
    . $section('По предметам')
    . ($subjectRows ? $tableStart . $subjectRows . '</table>' : $empty('Работ по предметам за эти 7 суток нет.'))
    . $section('Дополнительная практика и активность')
    . '<p style="font-size:14px;line-height:22px">ИИ-тренажёр: ' . (int)$trainer['attempted'] . ' заданий, '
    . (int)$trainer['correct'] . ' верных из ' . (int)$trainer['checked'] . ' проверенных.<br>'
    . 'Дни с зафиксированной активностью: ' . ($data['active_dates'] ? wr_escape(implode(', ', array_map(
      static fn($day) => (new DateTimeImmutable($day))->format('d.m'), $data['active_dates']
    ))) : 'нет') . '.</p>'
    . '<p style="font-size:12px;line-height:18px;color:#687486;border-top:1px solid #e7ebf0;padding-top:16px;margin-top:26px">'
    . 'Все показатели в письме относятся только к указанным 7 суткам. Сроки ДЗ учитывают персональные продления. '
    . 'Результаты работ на проверке не включены в средний балл.</p>'
    . '</div></div></body></html>';
}
