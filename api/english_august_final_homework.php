<?php

function english_august_final_base_questions(): array {
  $questions = [];
  $addText = static function (
    array &$target,
    string $label,
    string $sentence,
    string $base,
    array $correct,
    string $solution
  ): void {
    $target[] = [
      'type' => 'text',
      'text' => $label . "\n\n" . $sentence . "\n\nОбразуй подходящее прилагательное от **" . $base . "**.",
      'options' => null,
      'correct' => $correct,
      'solution' => $solution,
      'hint' => 'Впиши только образованное слово без точки и дополнительных пояснений.',
    ];
  };

  $exercise1 = [
    ["Everyone wants to be a more (1) ______ person in social situations.", 'confide', ['confident'], '**Confident** — уверенный. Суффикс *-ent* образует прилагательное от основы *confide*.'],
    ["First, make sure to approach others with (2) ______ gestures, like a warm smile.", 'affection', ['affectionate'], '**Affectionate gestures** — тёплые, дружелюбные жесты.'],
    ["Second, when you talk to someone, focus on (3) ______ topics that you both enjoy.", 'interest', ['interesting'], '**Interesting topics** — интересные темы; речь идёт о характеристике тем.'],
    ["By doing this, you're not just being (4) ______; you're also showing a genuine desire to build connections.", 'friend', ['friendly'], '**Friendly** — дружелюбный.'],
    ["Taking the time to give your full attention to the other person is vital in (5) ______ communication.", 'effect', ['effective'], '**Effective communication** — эффективное общение.'],
  ];
  foreach ($exercise1 as $index => [$sentence, $base, $correct, $solution]) {
    $addText($questions, 'Задание 1 · Building Connections · ' . ($index + 1) . '/5', $sentence, $base, $correct, $solution);
  }

  $exercise2 = [
    ["Being a (1) ______ communicator is essential for success in life.", 'technics', ['technical'], '**Technical communicator** — техничный коммуникатор.'],
    ["One key aspect is the ability to ask (2) ______ questions that delve deeper into a conversation.", 'mean', ['meaningful'], '**Meaningful questions** — содержательные вопросы.'],
    ["This provides room for (3) ______ responses and builds a deeper bond.", 'emotion', ['emotional'], '**Emotional responses** — эмоциональные ответы.'],
    ["Moreover, using clear and (4) ______ language reduces the risk of miscommunication.", 'articulation', ['articulate'], '**Articulate language** — чёткая, хорошо сформулированная речь.'],
  ];
  foreach ($exercise2 as $index => [$sentence, $base, $correct, $solution]) {
    $addText($questions, 'Задание 1 · Communicating Effectively · ' . ($index + 1) . '/4', $sentence, $base, $correct, $solution);
  }

  $exercise3 = [
    ["Does it feel (1) ______ during social interactions? You're not alone!", 'stress', ['stressful'], '**Stressful** — стрессовый, вызывающий стресс.'],
    ["Use your body language to express (2) ______ energy, allowing others to feel at ease as well.", 'calm', ['calming'], '**Calming energy** — успокаивающая энергия.'],
    ["A friendly demeanor can evoke (3) ______ interactions, making everyone feel light and welcomed.", 'pleasure', ['pleasurable'], '**Pleasurable interactions** — приятное общение.'],
    ["When talking, show your conversation partner how (4) ______ you are.", 'interest', ['interested'], '**Interested** описывает состояние человека: покажи, насколько ты заинтересован.'],
  ];
  foreach ($exercise3 as $index => [$sentence, $base, $correct, $solution]) {
    $addText($questions, 'Задание 1 · Creating a Positive Atmosphere · ' . ($index + 1) . '/4', $sentence, $base, $correct, $solution);
  }

  $exercise4 = [
    ["To be (1) ______, it's important to engage others genuinely.", 'charisma', ['charismatic'], '**Charismatic** — харизматичный.'],
    ["Start with a warm smile that will definitely make you more (2) ______ for everyone around.", 'like', ['likable', 'likeable'], '**Likable** — симпатичный, располагающий к себе. Допустимо также написание *likeable*.'],
    ["Another tip is to present ideas in a (3) ______ manner.", 'captivate', ['captivating'], '**Captivating manner** — увлекательная манера подачи.'],
    ["Lastly, remember that eye contact can make you (4) ______ to everyone even before you start speaking.", 'magnet', ['magnetic'], '**Magnetic** — притягательный.'],
    ["Using these techniques will make you more (5) ______ and attractive in any company.", 'notice', ['noticeable'], '**Noticeable** — заметный.'],
  ];
  foreach ($exercise4 as $index => [$sentence, $base, $correct, $solution]) {
    $addText($questions, 'Задание 1 · Developing Charisma · ' . ($index + 1) . '/5', $sentence, $base, $correct, $solution);
  }

  $suffixTasks = [
    [
      "If you learn to be adapt___ in any company by easily switching topics your partner enjoys discussing, everyone will be eager to be your friend.\n\nProve that you are a reli___ person by coming and leaving in time.\n\nThe desire to be liked is understand___, so don't be shy when speaking about yourself.",
      ['-able', '-ful', '-ic'], 0,
      '**-able**: adaptable, reliable, understandable.',
    ],
    [
      "As a mentor, it's important to be practic___ in your advice so that it resonates with your audience.\n\nEngaging in politic___ discussions can lead to arguments and even aversion.\n\nAlways pay attention to the physic___ cues in communication.",
      ['-al', '-ly', '-ent'], 0,
      '**-al**: practical, political, physical.',
    ],
    [
      "To become a more conf___ speaker, practise articulating your thoughts clearly.\n\nBeing dilig___ in your preparation for public speech will help you convey your ideas effectively.\n\nBeing pati___ listener is an invaluable trait.",
      ['-ive', '-ent', '-ful'], 1,
      '**-ent**: confident, diligent, patient.',
    ],
    [
      "A persuas___ approach can help you inspire your classmates to join your exciting project.\n\nSharing cool and impress___ experiences can make your point hit home.\n\nAlways be support___ of your friends' ideas.",
      ['-ive', '-ful', '-y'], 0,
      '**-ive**: persuasive, impressive, supportive.',
    ],
    [
      "An engag___ story can transport your classmates to another world.\n\nCultivating a welcom___ environment makes it easier for everyone to share their ideas.\n\nSeek out inspir___ leaders to motivate you on your journey to success.",
      ['-ing', '-ful', '-y'], 0,
      '**-ing**: engaging, welcoming, inspiring.',
    ],
  ];
  foreach ($suffixTasks as $index => [$sentences, $options, $correct, $solution]) {
    $questions[] = [
      'type' => 'single',
      'text' => 'Задание 2 · Общий суффикс · ' . ($index + 1) . "/5\n\nВыбери суффикс, который подходит ко всем трём словам.\n\n" . $sentences,
      'options' => $options,
      'correct' => [$correct],
      'solution' => $solution,
      'hint' => 'Подставь каждый вариант во все три слова и проверь, получаются ли нормативные прилагательные.',
    ];
  }

  return $questions;
}

function english_august_final_medium_questions(): array {
  $statements = "**1.** Eye contact is an important aspect of communication.\n\n"
    . "**2.** Imitating a person's behaviour makes them like you.\n\n"
    . "**3.** People tend to like famous people more than simple ones.\n\n"
    . "**4.** A hot drink helps to establish contact in many ways.\n\n"
    . "**5.** Watch your back if you want to make a good impression.\n\n"
    . "**6.** You should never forget what a person is called.\n\n"
    . "**7.** It's dishonest to use techniques to make someone like you.";

  $questions = [[
    'type' => 'text',
    'text' => "Задание 1 · Аудирование\n\nПрослушай шесть высказываний **A-F** и сопоставь их с утверждениями **1-7**. Одно утверждение лишнее. Введи шесть цифр подряд без пробелов.\n\n" . $statements,
    'options' => null,
    'correct' => ['162754', '1 6 2 7 5 4', '1,6,2,7,5,4'],
    'solution' => "Правильная последовательность: **162754**.\n\nA — 1: зрительный контакт.\n\nB — 6: важно помнить имя человека.\n\nC — 2: копирование жестов и манеры речи.\n\nD — 7: критика манипулятивных техник.\n\nE — 5: значение осанки для первого впечатления.\n\nF — 4: тёплая чашка помогает расположить человека.",
    'hint' => 'Каждое утверждение можно использовать только один раз. Запись в файле проигрывается дважды.',
    'audio' => true,
  ]];

  $formation = [
    ["Exchanging one's views on different things with peers has always been an important part of adolescents' (1) ______ *(socialize)*.", ['socialization', 'socialisation'], '**Socialization** — после притяжательной формы *adolescents’* требуется существительное. Британское написание *socialisation* также принимается.'],
    ["According to the data in the pie chart, 26% of the (2) ______ *(respond)* discuss tasks and grades with their peers at school.", ['respondents'], '**Respondents** — существительное во множественном числе после *26% of the*.'],
    ["(3) ______ *(additional)*, 14% of the surveyed teenagers talk about relationships with parents and siblings.", ['additionally'], '**Additionally** — вводное наречие.'],
    ["Additionally, 14% of the surveyed teenagers talk about (4) ______ *(relation)* with parents and siblings.", ['relationships'], '**Relationships** — существительное во множественном числе.'],
    ["(5) ______ *(predictable)*, the number of the teenagers who bring up such topics as popular games and songs at school is significantly higher.", ['predictably'], '**Predictably** — вводное наречие.'],
    ["The number of the teenagers who bring up such topics as popular games and songs at school (33%) is (6) ______ *(significant)* higher than that of the interviewees who share their thoughts on plans for the future (8%).", ['significantly'], '**Significantly higher** — наречие усиливает сравнительную степень прилагательного.'],
    ["This difference is not (7) ______ *(surprise)* as social and entertainment-related subjects are more prevalent for casual conversations.", ['surprising'], '**Surprising** — прилагательное, характеризующее разницу.'],
    ["Social and (8) ______ *(entertain)*-related subjects are more prevalent for casual conversations than future aspirations.", ['entertainment'], '**Entertainment-related subjects** — в сложном прилагательном используется существительное.'],
    ["(9) ______ *(regrettable)*, there are certain problems that can arise with communication with one's peers.", ['regrettably'], '**Regrettably** — вводное наречие «к сожалению».'],
    ["There are certain problems that can arise with (10) ______ *(communicate)* with one's peers.", ['communication'], '**Communication** — после *with* требуется существительное.'],
    ["Clashing opinions on one subject can lead to (11) ______ *(argue)*.", ['arguments'], '**Arguments** — существительное во множественном числе; при добавлении *-ment* конечная *e* исчезает.'],
    ["To solve the problem, a person should learn and practise basic communication strategies and develop conflict (12) ______ *(resolve)* skills.", ['resolution'], '**Conflict resolution skills** — навыки разрешения конфликтов.'],
    ["Communication with peers provides teenagers with (13) ______ *(essence)* support during the challenging period of adolescence.", ['essential'], '**Essential support** — необходимая, существенная поддержка.'],
  ];
  foreach ($formation as $index => [$sentence, $correct, $solution]) {
    $questions[] = [
      'type' => 'text',
      'text' => 'Задание 38 · Словообразование · ' . ($index + 1) . "/13\n\n" . $sentence,
      'options' => null,
      'correct' => $correct,
      'solution' => $solution,
      'hint' => 'Образуй слово от основы в скобках. Впиши только ответ для выделенного пропуска.',
      'image' => $index === 0 ? '/img/english-august-final/task38-chart.svg' : null,
    ];
  }

  return $questions;
}
