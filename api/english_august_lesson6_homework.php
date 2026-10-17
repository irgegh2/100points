<?php

function english_august_lesson6_base_questions(): array {
  $text = static function (string $label, string $prompt, array $answers, string $solution, string $hint): array {
    return [
      'type' => 'text',
      'text' => $label . "\n\n" . $prompt,
      'options' => null,
      'correct' => $answers,
      'solution' => $solution,
      'hint' => $hint,
    ];
  };

  $wordFormHint = 'Образуй подходящее по смыслу слово от слова в скобках. В ответе укажи только получившееся слово.';
  $gapHint = 'Впиши только пропущенное слово. Первая буква уже дана в задании.';
  $questions = [];

  $wordFormation = [
    [
      'energizing',
      "Hey study fam! As someone who's helped over 2M students ace their exams, I'm sharing my top hacks for (1) ______ (ENERGY) study sessions.",
      '**Energizing** - прилагательное «заряжающий энергией». Оно описывает существительное *study sessions*.',
    ],
    [
      'enjoyable',
      'First, create an (2) ______ (ENJOY) workspace - fairy lights, plants, whatever makes you happy!',
      '**Enjoyable** - прилагательное «приятный». После артикля *an* перед существительным *workspace* требуется прилагательное.',
    ],
    [
      'mindful',
      'Second, practice (3) ______ (MIND) studying instead of just scrolling through notes.',
      '**Mindful** означает «осознанный, внимательный». По смыслу противопоставляется бездумному просмотру конспектов.',
    ],
    [
      ['passively', 'passive'],
      'Second, practice mindful studying instead of just (4) ______ (PASSIVE) scrolling through notes.',
      '**Passively** - «пассивно». В этой конструкции также допустимо **passive**, так как слово может характеризовать герундий *scrolling* как способ учёбы.',
    ],
    [
      'drained',
      "When you're feeling completely (5) ______ (DRAIN), don't push through - take that power nap!",
      '**Drained** - причастие-прилагательное «измотанный, лишённый сил». После *feel completely* описывается состояние человека.',
    ],
    [
      'opposites',
      "And remember, there's no shame in using a flashcard app to explore synonyms and (6) ______ (OPPOSE) in a fun and engaging way.",
      '**Opposites** - существительное во множественном числе «антонимы, противоположности», однородное со словом *synonyms*.',
    ],
  ];
  foreach ($wordFormation as $index => $item) {
    [$answer, $prompt, $solution] = $item;
    $questions[] = $text(
      'Задание 1 · Словообразование · ' . ($index + 1) . '/6',
      $prompt,
      is_array($answer) ? $answer : [$answer],
      $solution,
      $wordFormHint
    );
  }

  $dialogue = [
    ['comfort', "I like that I can study from the (1) c______ of my home without having to commute to school every day.", '**Comfort** completes the phrase *from the comfort of my home* - «не выходя из домашнего комфорта».'],
    ['commute', "I like that I can study from the comfort of my home without having to (2) c______ to school every day.", '**Commute** - регулярно ездить между домом и местом учёбы или работы.'],
    ['pace', "I also enjoy how I can pause videos or reread materials at my own (3) p______ when things don't immediately come into my mind.", '**Pace** completes the phrase *at my own pace* - «в собственном темпе».'],
    ['quizzing', 'I know that (4) q______ yourself works way better than passive studying where you just reread your notes.', '**Quizzing yourself** - самотестирование. Герундий выступает подлежащим предложения.'],
    [['reread', 're-read'], 'I know that quizzing yourself works way better than passive studying where you just (5) r______ your notes.', '**Reread** - перечитывать. После *you just* используется начальная форма глагола.'],
    ['imaginary', 'I also find that teaching the material to an (6) i______ student helps you realize what you actually understand versus what you need to revise more.', '**Imaginary student** - «воображаемый ученик». Перед существительным требуется прилагательное.'],
    ['refresh', 'Proper revision is an opportunity to (7) r______ your brain in an enjoyable way.', '**Refresh your brain** - «освежить знания, перезагрузить мозг». После *to* нужна начальная форма глагола.'],
    [['flashcards', 'flash cards'], 'Effective revision is active, not passive, which is why techniques like (8) f______ work better than just mindlessly rereading notes.', '**Flashcards** - карточки для запоминания. Подлежащее во множественном числе согласуется с *work*.'],
    ['mindlessly', 'Techniques like flashcards work better than just (9) m______ rereading notes.', '**Mindlessly** - наречие «бездумно», характеризующее способ перечитывания.'],
    ['drained', 'When I feel completely (10) d______ but need to keep going, I take a quick power nap.', '**Drained** - «полностью измотанный». Слово описывает состояние говорящего после *feel*.'],
    ['nap', 'I take a quick power (11) n______ for about 20 minutes to refresh my brain.', '**Nap** completes the collocation *power nap* - короткий восстанавливающий сон.'],
    ['switch', 'Sometimes I also (12) s______ rooms because a change of environment can make you more awake.', '**Switch rooms** - «сменить комнату, поменять место занятий». После *I also* используется форма Present Simple.'],
    ['boost', "A change of environment can make you more awake and (13) b______ focus when you're wiped out.", '**Boost focus** - повысить концентрацию. Глагол однороден конструкции после *can*.'],
    ['app', 'I use a flashcard (14) a______ to build my vocabulary during my commute to school.', '**Flashcard app** - приложение с карточками для запоминания.'],
    ['build', 'I use a flashcard app to (15) b______ my vocabulary during my commute to school.', '**Build my vocabulary** - расширять словарный запас. После *to* нужна начальная форма глагола.'],
    ['opposites', 'I also try to explore synonyms and (16) o______ of new words I learn.', '**Opposites** - антонимы, противоположные по смыслу слова; форма множественного числа сочетается с *synonyms*.'],
  ];
  foreach ($dialogue as $index => $item) {
    [$answer, $prompt, $solution] = $item;
    $questions[] = $text(
      'Задание 2 · Устная часть: Studies · пропуск ' . ($index + 1) . '/16',
      $prompt,
      is_array($answer) ? $answer : [$answer],
      $solution,
      $gapHint
    );
  }

  $questions[] = $text(
    'Задание 3 · Идиомы со словом mind',
    "Подбери подходящие идиомы к пяти публикациям и запиши цепочку из пяти букв.\n\n"
      . "a) out of sight, out of mind\n\n"
      . "b) speak your mind\n\n"
      . "c) in two minds\n\n"
      . "d) slip of the mind\n\n"
      . "e) mind your own business\n\n"
      . "**Story 1:** When people judge my study methods, I just tell them to (1) ______. What works for me might not work for you!\n\n"
      . "**Story 2:** Totally forgot about my essay deadline - complete (2) ______! Thank god for reminder apps.\n\n"
      . "**Story 3:** I'm (3) ______ about taking a gap year. Part of me wants to travel, part wants to start uni immediately.\n\n"
      . "**Story 4:** Sometimes you need to (4) ______ in study groups instead of just agreeing with everyone.\n\n"
      . "**Story 5:** Moved my PlayStation to the closet - (5) ______! Now I actually study in my room.",
    ['edcba', 'e d c b a', 'e,d,c,b,a', 'e;d;c;b;a'],
    "**1 - e) mind your own business:** не вмешивайся, занимайся своим делом.\n\n"
      . "**2 - d) slip of the mind:** оговорка или забывчивость; что-то вылетело из головы.\n\n"
      . "**3 - c) in two minds:** сомневаться между двумя решениями.\n\n"
      . "**4 - b) speak your mind:** открыто высказать своё мнение.\n\n"
      . "**5 - a) out of sight, out of mind:** с глаз долой - из сердца вон.\n\n"
      . '**Ответ:** edcba.',
    'Запиши только пять букв подряд. Пробелы или запятые между буквами также допустимы.'
  );

  return $questions;
}

function english_august_lesson6_hard_questions(): array {
  $text = static function (string $label, string $prompt, array $answers, string $solution, string $hint): array {
    return [
      'type' => 'text',
      'text' => $label . "\n\n" . $prompt,
      'options' => null,
      'correct' => $answers,
      'solution' => $solution,
      'hint' => $hint,
    ];
  };
  $single = static function (string $label, string $prompt, array $options, int $correct, string $solution): array {
    return [
      'type' => 'single',
      'text' => $label . "\n\n" . $prompt,
      'options' => $options,
      'correct' => [$correct],
      'solution' => $solution,
      'hint' => 'Посмотри видео и выбери один правильный вариант.',
    ];
  };

  $questions = [];
  $definitionHint = 'Впиши английское слово или выражение, соответствующее определению. Количество пропусков подсказывает количество слов.';
  $definitions = [
    [['wiped out', 'wipedout'], 'feeling exhausted - feeling ______ ______', '**Wiped out** - совершенно измотанный, очень уставший.'],
    [['mindlessly'], 'without thinking - ______', '**Mindlessly** - бездумно, не задумываясь.'],
    [['unsurprisingly'], 'predictably, no surprise - ______', '**Unsurprisingly** - неудивительно, предсказуемо.'],
    [['opportunity'], 'a chance to do something - an ______', '**Opportunity** - возможность, шанс сделать что-либо.'],
    [['minuscule'], 'very small - ______', '**Minuscule** - крошечный, чрезвычайно маленький.'],
    [['on your own', 'onyourown'], 'studying by yourself - studying ______ ______ ______', '**On your own** - самостоятельно, без помощи других.'],
    [['at your own pace', 'atyourownpace'], 'without hurry, at the speed that is natural for you - ______ ______ ______ ______', '**At your own pace** - в собственном, удобном для себя темпе.'],
    [['power nap', 'powernap'], 'brief sleep during the day - ______ ______', '**Power nap** - короткий дневной сон для восстановления сил.'],
    [['revise'], 'refresh something in your memory - ______', '**Revise** - повторять изученное, освежать знания в памяти.'],
    [['bear in mind', 'bearinmind', 'keep in mind', 'keepinmind'], 'remember, take into account - ______ ______ ______', '**Bear in mind / keep in mind** - помнить, принимать во внимание.'],
  ];
  foreach ($definitions as $index => [$answers, $prompt, $solution]) {
    $questions[] = $text('Задание 1 · Определения · ' . ($index + 1) . '/10', $prompt, $answers, $solution, $definitionHint);
  }

  $wordFormHint = 'Образуй подходящее по смыслу слово от слова в скобках. В ответе укажи только получившееся слово.';
  $wordFormation = [
    ['healthy', 'According to experts, doing puzzles keeps our brains fit and (1) ______ (HEALTH).', '**Healthy** - после *fit and* требуется однородное прилагательное: «подтянутым и здоровым».'],
    ['satisfaction', 'As well as gaining (2) ______ (SATISFY) from finding the correct answer to a difficult problem, we give our brains a good workout.', '**Satisfaction** - после *gaining* требуется существительное: *gain satisfaction* - получать удовлетворение.'],
    ['successful', "All sorts of handheld 'brain games' are now available in the shops, and the most (3) ______ (SUCCESS) games have sold in their millions.", '**Successful** - конструкция *the most ... games* требует прилагательного в превосходной степени.'],
    ['discover', "What's more, people (4) ______ (COVER) that the more they play the games, the easier it is to find a solution.", '**Discover** - глагол «обнаруживать». Приставка *dis-* меняет значение исходного *cover*.'],
    ['solution', 'The more people play the games, the easier it is to find a (5) ______ (SOLVE) to the problems posed.', '**Solution** - после артикля *a* требуется существительное: «найти решение».'],
    ['improvement', 'They see this as proof that there has been an (6) ______ (IMPROVE) in the power of their brains.', '**Improvement** - после *an* требуется существительное «улучшение».'],
    ['scientists', 'Some (7) ______ (SCIENCE) argue that the brain gets better at any task the more often it is repeated.', '**Scientists** - нужны люди, которые выдвигают аргумент; форма *argue* указывает на множественное число.'],
    ['performance', 'The improvement in the (8) ______ (PERFORM) of the brain is something that happens naturally.', '**Performance** - существительное «работа, функционирование» после артикля *the*.'],
    ['uncertain', 'Although these brain games are obviously fun to play, it remains (9) ______ (CERTAIN) whether they are actually helping to boost brainpower or not.', '**Uncertain** - по смыслу «остаётся неясным»; отрицательное значение создаёт приставка *un-*.'],
  ];
  foreach ($wordFormation as $index => [$answer, $prompt, $solution]) {
    $questions[] = $text('Задание 2 · Словообразование · ' . ($index + 1) . '/9', $prompt, [$answer], $solution, $wordFormHint);
  }

  $questions[] = $single(
    'Задание 3 · Видео · вопрос 1/7',
    'What do we learn about Brian at the very beginning of his talk?',
    ['He started learning a foreign language 3 years ago.', 'He speaks 5 languages.', 'He has invented an effective method of learning languages quickly.'],
    1,
    'Brian перечисляет английский, испанский, французский, китайский и арабский - всего **пять языков**.\n\n**Ответ:** 2.'
  );
  $questions[] = $single(
    'Задание 3 · Видео · вопрос 2/7',
    'What is NOT TRUE about the language learning method?',
    ["It's easy to master it.", "It's fun to use.", "It doesn't require much money."],
    2,
    'В приложенном ключе указан **вариант 3**. При этом в видео метод назван *free*, поэтому фраза «не требует много денег» буквально выглядит верной: в исходном задании или ключе есть смысловое противоречие. Автопроверка настроена по приложенному ключу.\n\n**Ответ по ключу:** 3.'
  );
  $questions[] = $single(
    'Задание 3 · Видео · вопрос 3/7',
    'Why are YouTube videos like “Learn a language in two weeks” bad?',
    ['They contain an unbelievable amount of negative information.', 'They can form dangerous misassumptions.', 'They do not teach real language.'],
    1,
    'Автор предупреждает, что такие ролики создают ложные ожидания, а ложные ожидания опасны.\n\n**Ответ:** 2.'
  );
  $questions[] = $single(
    'Задание 3 · Видео · вопрос 4/7',
    'What is the key factor determining the time needed for language acquisition?',
    ['The degree of proximity between languages', 'The readiness to spend a good deal of time', 'The level of comfort while studying'],
    0,
    'Срок зависит от того, насколько новый язык близок к уже известному: близкий язык осваивается быстрее, далёкий требует больше времени.\n\n**Ответ:** 1.'
  );
  $questions[] = $single(
    'Задание 3 · Видео · вопрос 5/7',
    'According to Brian, fluency is the ability...',
    ['to understand and speak freely whatever the setting.', 'to speak fast in a variety of different situations.', 'to express oneself comfortably with other people.'],
    0,
    'Brian определяет fluency как способность понимать других и свободно выражать себя практически в любом контексте.\n\n**Ответ:** 1.'
  );
  $questions[] = $single(
    'Задание 3 · Видео · вопрос 6/7',
    'What is NOT mentioned as a goal at the beginner stage?',
    ['following what is said on an elementary level', 'expanding vocabulary with the help of specific resources', 'producing easy-to-build utterances'],
    1,
    'На начальном этапе говорится о понимании основ и построении простых предложений. Расширение словаря с помощью конкретных ресурсов как цель не называется.\n\n**Ответ:** 2.'
  );
  $questions[] = $single(
    'Задание 3 · Видео · вопрос 7/7',
    "What's the most important thing to do while learning a language?",
    ['Trying your best to achieve perfect pronunciation', 'Reading a lot about the language', 'Listening and speaking a lot'],
    2,
    'Главная рекомендация - как можно больше **слушать и говорить**, то есть практиковать реальное использование языка.\n\n**Ответ:** 3.'
  );

  return $questions;
}
