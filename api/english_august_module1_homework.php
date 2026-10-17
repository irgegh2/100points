<?php

function english_august_module1_base_questions(): array {
  $listenHint = 'Прослушай запись и впиши ровно то слово или словосочетание, которое звучит на месте пропуска.';
  $audio = [
    ['silently', "Sarah's morning starts when her smartwatch (1) ______ wakes her up.", 'В записи звучит **silently wakes her up** - «тихо будит её».'],
    ['are installing', 'She lives in Shanghai where more and more families (2) ______ solar panels on their rooftops.', 'В записи: **are installing solar panels**. Действие становится всё более распространённым сейчас, поэтому используется Present Continuous.'],
    ['wireless', 'After breakfast, she puts on her (3) ______ earbuds.', 'В записи: **wireless earbuds** - беспроводные наушники.'],
    ['tangled', 'Her old headphones always get (4) ______ in her backpack.', 'В записи: **get tangled** - запутываются.'],
    ['digital payment', 'At the local coffee shop, Sarah uses (5) ______ systems instead of cash.', 'В записи: **digital payment systems** - цифровые платёжные системы.'],
    ['lasts', "Her phone battery (6) ______ only about five hours when she's playing games a lot.", 'В записи: **battery lasts**. С подлежащим в единственном числе глагол получает окончание **-s**.'],
    ['artificial', 'For her university work, she sometimes uses (7) ______ intelligence tools.', 'В записи: **artificial intelligence tools** - инструменты искусственного интеллекта.'],
    ['processor', "On weekends, Sarah's family uses their new food (8) ______ to make healthy drinks quickly.", 'В записи: **food processor** - кухонный комбайн.'],
    ['vehicle', "Her brother is saving money for Tesla's electric (9) ______.", 'В записи: **electric vehicle** - электромобиль.'],
    ['virtual', 'They use (10) ______ reality headsets to play games and watch special movies.', 'В записи: **virtual reality headsets** - очки виртуальной реальности.'],
    ['are making', "We don't think about how these technologies (11) ______ our lives easier.", 'В записи: **are making our lives easier** - делают нашу жизнь проще.'],
  ];

  $questions = [];
  foreach ($audio as $index => [$answer, $sentence, $solution]) {
    $questions[] = [
      'type' => 'text',
      'text' => 'Задание 1 · Аудирование · пропуск ' . ($index + 1) . "/11\n\n" . $sentence,
      'options' => null,
      'correct' => [$answer],
      'solution' => $solution,
      'hint' => $listenHint,
      'audio' => true,
    ];
  }

  $wordBank = ['processor', 'lasts', 'payment', 'earbuds', 'vehicle', 'reality', 'tangled', 'panels', 'charge', 'intelligence'];
  $vocabulary = [
    [7, 'In China more and more households are starting to use solar ______ on their rooftops.', '**Panels**: solar panels - солнечные панели.'],
    [3, 'My wireless ______ connect automatically to my phone when I open the case.', '**Earbuds**: wireless earbuds - беспроводные наушники-вкладыши.'],
    [2, 'Digital ______ systems are replacing cash in most cafes and stores nowadays.', '**Payment**: digital payment systems - цифровые платёжные системы.'],
    [8, "I need to ______ my device every night because I'm using it all day for TikTok.", '**Charge** - заряжать устройство.'],
    [1, 'The battery ______ only 5 hours when I am gaming non-stop.', '**Lasts** - длится, работает в течение указанного времени.'],
    [9, 'Artificial ______ helps students write essays and solve math problems.', '**Intelligence** completes the phrase *artificial intelligence*.'],
    [6, "My old headphones always get ______ in my backpack - that's why I'm switching to wireless earbuds.", '**Tangled** - запутанный; *get tangled* - запутываться.'],
    [4, "Tesla's electric ______ is becoming more popular among young professionals.", '**Vehicle** - транспортное средство; *electric vehicle* - электромобиль.'],
    [5, 'Virtual ______ headsets are transforming how we play games and watch movies.', '**Reality** completes the phrase *virtual reality*.'],
    [0, "This food ______ makes cooking so much easier - I'm using it for smoothies every morning.", '**Processor**: food processor - кухонный комбайн.'],
  ];
  foreach ($vocabulary as $index => [$correct, $sentence, $solution]) {
    $questions[] = [
      'type' => 'single',
      'text' => 'Задание 2 · Лексика · ' . ($index + 1) . "/10\n\n" . $sentence,
      'options' => $wordBank,
      'correct' => [$correct],
      'solution' => $solution,
      'hint' => 'Выбери одно слово из банка. Обрати внимание на устойчивые сочетания слов.',
      'audio' => false,
    ];
  }

  $grammar = [
    [['are changing'], 'Delivery drones (1) ______ *(change)* how we get our food and packages.', '**Are changing**: речь о происходящем сейчас изменении, подлежащее *drones* во множественном числе.'],
    [['carry'], 'These flying robots (2) ______ *(carry)* items through the air.', '**Carry**: это общее описание работы дронов, поэтому Present Simple.'],
    [['faster'], 'They are much (3) ______ *(fast)* than cars in busy traffic.', '**Faster**: после *than* нужна сравнительная степень; *much* усиливает сравнение.'],
    [['are testing'], 'Amazon and other companies (4) ______ *(test)* drone delivery in many cities right now.', '**Are testing**: маркер *right now* требует Present Continuous; подлежащее множественного числа.'],
    [['drop'], "The drone uses GPS and cameras to find the exact address and (5) ______ *(drop)* packages safely in people's yards.", '**Drop**: однородные инфинитивы после *to*: *to find ... and drop ...*. Повторять *to* необязательно.'],
    [['people'], 'In some areas, drones deliver medicine to sick (6) ______ *(person)* in just 15 minutes.', '**People** - неправильная форма множественного числа от *person*.'],
    [['cheaper'], 'This technology is getting (7) ______ *(cheap)* and more reliable every month.', '**Cheaper**: конструкция *is getting* показывает изменение, нужна сравнительная степень.'],
    [['quickest'], 'Soon, drone delivery will be the (8) ______ *(quick)* way to get things we need without leaving home.', '**Quickest**: артикль *the* и сравнение со всеми способами требуют превосходной степени.'],
  ];
  foreach ($grammar as $index => [$answers, $sentence, $solution]) {
    $questions[] = [
      'type' => 'text',
      'text' => 'Задание 3 · Грамматика · ' . ($index + 1) . "/8\n\n" . $sentence,
      'options' => null,
      'correct' => $answers,
      'solution' => $solution,
      'hint' => 'Поставь слово в скобках в подходящую форму. Учитывай Present Simple, Present Continuous, степени сравнения и множественное число.',
      'audio' => false,
    ];
  }

  return $questions;
}

function english_august_module1_medium_questions(): array {
  $definitions = [
    ['A small cleaning device that moves around your house independently and removes dust and dirt from floors.', ['robot vacuum cleaner', 'food processor', 'smartwatch'], 0, '**Robot vacuum cleaner** - робот-пылесос, который самостоятельно перемещается по дому и убирает пол.'],
    ['Websites and applications where people share photos, videos, and messages with friends and followers.', ['artificial intelligence', 'social media platforms', 'augmented reality'], 1, '**Social media platforms** - платформы социальных сетей.'],
    ['Special eyewear that shows digital information and virtual objects over the real world you see.', ['wireless earbuds', 'augmented reality glasses', 'digital payment systems'], 1, '**Augmented reality glasses** накладывают цифровые объекты на реальный мир.'],
    ['A wearable device on your wrist that shows time, messages, health data and connects to your phone.', ['smartwatch', 'food processor', '3D printer'], 0, '**Smartwatch** - умные часы.'],
    ['To transfer files, photos, or videos from your device to the internet or cloud storage.', ['swipe', 'upload', 'power'], 1, '**Upload** - загружать файл с устройства в интернет или облако.'],
    ['To say what will happen in the future based on information, data, or artificial intelligence.', ['avoid', 'predict', 'power'], 1, '**Predict** - предсказывать на основе информации или данных.'],
    ['Easy to use, obtain, or approach; available for everyone including people with disabilities.', ['accessible', 'latest', 'current'], 0, '**Accessible** - доступный, в том числе для людей с инвалидностью.'],
    ['Happening by itself without human control or intervention, using programmed instructions.', ['frequently', 'silently', 'automatically'], 2, '**Automatically** - автоматически, без вмешательства человека.'],
  ];

  $questions = [];
  foreach ($definitions as $index => [$text, $options, $correct, $solution]) {
    $questions[] = [
      'type' => 'single',
      'text' => 'Задание 1 · Определения · ' . ($index + 1) . "/8\n\n" . $text,
      'options' => $options,
      'correct' => [$correct],
      'solution' => $solution,
      'hint' => 'Выбери слово или выражение, которое точно соответствует определению.',
    ];
  }

  $video = [
    [['communicate'], 'This pocket-sized device has transformed how we (1) ______ *(communicate)*, work, and access information.', '**Communicate**: после подлежащего *we* в Present Simple используется начальная форма глагола.'],
    [['your'], "It's like having a supercomputer in (2) ______ *(you)* hand.", '**Your**: перед существительным *hand* нужно притяжательное прилагательное.'],
    [['allows'], 'This breakthrough (3) ______ *(allow)* scientists to modify DNA with unprecedented precision.', '**Allows**: *this breakthrough* - 3-е лицо единственного числа в Present Simple.'],
    [['is opening', 'isopening'], 'It (4) ______ *(open)* new doors for treating genetic diseases and revolutionizing medicine.', '**Is opening**: процесс и его влияние продолжаются сейчас, поэтому Present Continuous.'],
    [['diseases'], 'It is opening new doors for treating genetic (5) ______ *(disease)* and revolutionizing medicine.', '**Diseases**: речь идёт о разных генетических заболеваниях, поэтому множественное число.'],
    [['these'], 'Number 3. Electric cars, pioneered by companies like Tesla. (6) ______ *(This)* vehicles...', '**These**: местоимение согласуется с существительным *vehicles* во множественном числе.'],
    [['are leading', 'areleading'], 'These vehicles (7) ______ *(lead)* the charge towards a greener future.', '**Are leading**: речь о происходящем сейчас процессе, подлежащее во множественном числе.'],
    [['more sustainable', 'moresustainable'], 'These vehicles are leading the charge towards a greener, (8) ______ *(sustainable)* future.', '**More sustainable**: для многосложного прилагательного сравнительная степень строится с *more*.'],
    [['are reshaping', 'arereshaping'], 'They (9) ______ *(reshape)* the automotive industry.', '**Are reshaping**: изменение отрасли происходит сейчас, поэтому Present Continuous.'],
    [['our'], 'They are reshaping the automotive industry and (10) ______ *(we)* approach to transportation.', '**Our**: перед существительным *approach* нужно притяжательное прилагательное.'],
  ];
  foreach ($video as $index => [$answers, $text, $solution]) {
    $questions[] = [
      'type' => 'text',
      'text' => 'Задание 2 · Видео · пропуск ' . ($index + 1) . "/10\n\n" . $text,
      'options' => null,
      'correct' => $answers,
      'solution' => $solution,
      'hint' => 'Поставь слово в скобках в подходящую форму. Иногда форма Present Simple совпадает с исходной.',
    ];
  }

  return $questions;
}
