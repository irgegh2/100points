<?php

function english_august_lesson7_base_questions(): array {
  $single = static function (string $label, string $sentence, array $options, $correct, string $solution): array {
    return [
      'type' => 'single',
      'text' => $label . "\n\n" . $sentence,
      'options' => $options,
      'correct' => is_array($correct) ? $correct : [$correct],
      'solution' => $solution,
      'hint' => 'Выбери подходящий глагол. Play употребляется с играми и инструментами, do - с занятиями и упражнениями, go - с активностями на -ing.',
    ];
  };
  $questions = [];
  $verbs = [
    ["I'm not sure, but I guess ______ computer games is probably one of the most popular pastimes among zoomers today.", ['playing', 'doing', 'going'], 0, '**Playing computer games**: с играми употребляется *play*.'],
    ['My sister wants to ______ surfing during our summer vacation in California.', ['play', 'do', 'go'], 2, '**Go surfing**: с активностями на *-ing* обычно употребляется *go*.'],
    ['Many teenagers ______ yoga to reduce stress and improve their mental health.', ['play', 'do', 'go'], 1, '**Do yoga**: с упражнениями и занятиями употребляется *do*.'],
    ["Have you ever tried to ______ the electric guitar? It's harder than it looks on TikTok.", ['play', 'do', 'go'], 0, '**Play the electric guitar**: с музыкальными инструментами употребляется *play*.'],
    ['He started ______ photography last year and now has over 10,000 followers on Instagram.', ['playing', 'doing', 'going'], 1, '**Doing photography**: фотография здесь обозначает занятие.'],
    ['We usually ______ basketball after school on Thursdays at the community center.', ['play', 'do', 'go'], 0, '**Play basketball**: с командными играми употребляется *play*.'],
    ['She loves to ______ karaoke with her friends during weekend sleepovers.', ['play', 'do', 'go'], 1, '**Do karaoke** - вариант из приложенного ключа.'],
    ['My brother decided to ______ martial arts after watching too many anime shows.', ['play', 'do', 'go'], 1, '**Do martial arts**: с боевыми искусствами употребляется *do*.'],
    ['Would you like to ______ skateboarding with us at the new park this Saturday?', ['play', 'do', 'go'], 2, '**Go skateboarding**: активность на *-ing* сочетается с *go*.'],
    ["I've been ______ puzzles on my phone since the pandemic started.", ['playing', 'doing', 'going'], 1, '**Doing puzzles**: головоломки решают как занятие, поэтому используется *do*.'],
    ['Many influencers ______ vlogs to document their daily lives and connect with fans.', ['play', 'do', 'go'], 1, '**Do vlogs** - вариант из приложенного ключа.'],
    ['My friends invited me to ______ bowling after the final exams next week.', ['play', 'do', 'go'], [0, 2], '**Go bowling** - наиболее естественный вариант в этом предложении. В приложенном ключе также допускается *play bowling*, поэтому автопроверка принимает оба ответа.'],
    ["She's learning to ______ the drums through YouTube tutorials.", ['play', 'do', 'go'], 0, '**Play the drums**: с музыкальными инструментами употребляется *play*.'],
    ["They're ______ parkour in the abandoned building, which looks dangerous.", ['playing', 'doing', 'going'], 1, '**Doing parkour**: parkour рассматривается как занятие.'],
    ['We should ______ shopping for new sneakers before the concert.', ['play', 'do', 'go'], 2, '**Go shopping** - устойчивое сочетание.'],
  ];
  foreach ($verbs as $index => [$sentence, $options, $correct, $solution]) {
    $questions[] = $single('Задание 1 · Play / do / go · вопрос ' . ($index + 1) . '/15', $sentence, $options, $correct, $solution);
  }
  $bank = ['feedback', 'boost', 'storage', 'lasting', 'cope', 'relaxing', 'weather conditions', 'go', 'ordinary', 'injured', 'physically', 'helmet', 'unique', 'remove', 'take'];
  $gaps = [
    ['The main benefit of physical hobbies is that they are a great way to ______ with stress while keeping your body healthy.', 4, '**Cope with stress** - справляться со стрессом.'],
    ['As for the advantages of intellectual hobbies, they help ______ your imagination and improve your thinking skills.', 1, '**Boost your imagination** - развивать воображение.'],
    ["For example, when you do boxing or ______ diving, you can get seriously injured if you're not careful.", 7, '**Go diving** - заниматься дайвингом.'],
    ['As for intellectual hobbies, they often ______ up a lot of your time.', 14, '**Take up a lot of time** - занимать много времени.'],
    ['The main benefit of outdoor hobbies is that they allow you to see beauty in ______ things while enjoying fresh air.', 8, '**Ordinary things** - обычные вещи.'],
    ['Indoor hobbies can be enjoyed regardless of ______ and often require less special equipment.', 6, '**Regardless of weather conditions** - независимо от погодных условий.'],
    ['Activities like cycling require special gear such as a ______.', 11, '**Helmet** - шлем.'],
    ['Indoor hobbies often require ______ space for equipment like art supplies or a chess board.', 2, '**Storage space** - место для хранения.'],
    ['The main benefit of solo hobbies is that they provide ______ pastime activities you can enjoy without depending on others.', 5, '**Relaxing pastime activities** - расслабляющие занятия.'],
    ['Social hobbies help create ______ friendships and make shared experiences more enjoyable.', 3, '**Lasting friendships** - крепкая, продолжительная дружба.'],
    ['You might receive negative ______ that can discourage you from continuing.', 0, '**Negative feedback** - негативные отзывы.'],
    ['Art-related hobbies allow you to express yourself and create ______ gifts for friends and family.', 12, '**Unique gifts** - уникальные подарки.'],
    ['Sports-related hobbies keep you ______ active and are an excellent way to release energy.', 10, '**Physically active** - физически активным.'],
    ['When painting or doing pottery, stains are hard to ______ from clothes.', 13, '**Remove stains** - удалить пятна.'],
    ['There is always a risk you might get seriously ______ during activities like boxing.', 9, '**Get seriously injured** - серьёзно травмироваться.'],
  ];
  foreach ($gaps as $index => [$sentence, $correct, $solution]) {
    $questions[] = [
      'type' => 'single',
      'text' => 'Задание 2 · Лексика · пропуск ' . ($index + 1) . "/15\n\n" . $sentence,
      'options' => $bank,
      'correct' => [$correct],
      'solution' => $solution,
      'hint' => 'Выбери одно слово или выражение из банка. Каждая позиция используется по смыслу и грамматике.',
    ];
  }

  $fragments = "**a)** Well, personally I would prefer to take up gardening as it can help me relax after a busy day at work and provides physical activity that is good for my health.\n\n"
    . "**b)** There are certain differences between the pictures.\n\n"
    . "**c)** As for THE OTHER photo, it shows a teenage boy. He is playing the piano in the living room of the house or flat where he lives.\n\n"
    . "**d)** As for the advantages of playing the piano, it helps boost your imagination and develop creative skills.\n\n"
    . "**e)** The key difference is that one picture illustrates an outdoor pastime, whereas the other picture demonstrates an indoor free time activity.\n\n"
    . "**f)** Hi Tanya! I've found two photos for our project “Hobbies”. I'd like to describe them to you.\n\n"
    . "**g)** As for playing the piano, it requires a lot of practice time and the instrument can be quite expensive to buy and maintain.\n\n"
    . "**h)** These photos perfectly suit our project because they illustrate two most common types of hobbies.\n\n"
    . "**i)** The main benefit of gardening is that it allows you to connect with nature and see beauty in ordinary things while getting fresh air.\n\n"
    . "**j)** That's all I wanted to tell you. Please, call me back as soon as possible.\n\n"
    . "**k)** On the other hand, gardening has certain disadvantages. For example, you can only do it in certain seasons, and you need storage space for tools like a watering can and plant supplies.\n\n"
    . "**l)** In ONE photo there is a young woman. She is planting flowers in the garden.\n\n"
    . "**m)** Both hobbies have their advantages and disadvantages.";
  $questions[] = [
    'type' => 'text',
    'text' => "Задание 3 · Устная часть · порядок реплик\n\nСобери логичный ответ из фрагментов **a-m** и введи последовательность букв без пробелов.\n\n" . $fragments,
    'options' => null,
    'correct' => ['flchbemidkgaj', 'f l c h b e m i d k g a j'],
    'solution' => "Правильный порядок: **f-l-c-h-b-e-m-i-d-k-g-a-j**.\n\nСначала идёт приветствие и описание двух фотографий, затем обоснование выбора, различия, плюсы и минусы, личное мнение и завершение сообщения.",
    'hint' => 'Начни с приветствия, затем опиши обе фотографии. После этого переходи к сравнению, преимуществам, недостаткам, личному мнению и прощанию.',
  ];

  return $questions;
}

function english_august_lesson7_medium_questions(): array {
  $textQuestion = static function (string $label, string $sentence, array $correct, string $solution, string $hint): array {
    return ['type' => 'text', 'text' => $label . "\n\n" . $sentence, 'options' => null, 'correct' => $correct, 'solution' => $solution, 'hint' => $hint];
  };
  $questions = [];

  $formation = [
    ['When I decided to start painting last year, I did not realize how much this (1) ______ *(intellect)* hobby would change my life.', ['intellectual'], '**Intellectual** - прилагательное перед существительным *hobby*.'],
    ['At first, I had no (2) ______ *(inspire)* and my drawings looked terrible.', ['inspiration'], '**Inspiration** - после *no* требуется существительное.'],
    ['I kept practising regularly and eventually began to boost my (3) ______ *(imagine)* with different techniques.', ['imagination'], '**Imagination** - существительное после притяжательного *my*.'],
    ['My art supplies required a lot of (4) ______ *(store)* space.', ['storage'], '**Storage space** - место для хранения; существительное *storage* определяет назначение пространства.'],
  ];
  foreach ($formation as $index => [$sentence, $correct, $solution]) {
    $questions[] = $textQuestion('Задание 1 · Словообразование · ' . ($index + 1) . '/4', $sentence, $correct, $solution, 'Образуй подходящее по смыслу слово от основы в скобках.');
  }

  $collocations = [
    ['Even though it is a physically (1) d______ hobby, I enjoy every minute of it.', ['demanding'], '**Physically demanding** - физически затратный, требовательный.'],
    ['Sometimes I get negative (2) f______ from people who do not understand urban art.', ['feedback'], '**Negative feedback** - негативные отзывы.'],
    ['I always carry my art (3) s______ in my backpack.', ['supplies'], '**Art supplies** - художественные принадлежности.'],
    ['The hardest part is that stains are hard to (4) r______ from my clothes.', ['remove'], '**Remove stains** - удалить пятна.'],
  ];
  foreach ($collocations as $index => [$sentence, $correct, $solution]) {
    $questions[] = $textQuestion('Задание 2 · Коллокации · ' . ($index + 1) . '/4', $sentence, $correct, $solution, 'Восстанови коллокацию по первой букве и смыслу предложения.');
  }

  $synonyms = [
    ['I love creating (1) ______ *(one-of-a-kind)* gifts for my family and friends.', ['unique'], '**Unique** - синоним *one-of-a-kind*.'],
    ['I have been going (2) ______ *(riding a bicycle)* with her almost every weekend.', ['cycling'], '**Go cycling** - кататься на велосипеде.'],
    ['We have created (3) ______ *(long-term)* friendships with other cyclists.', ['lasting'], '**Lasting friendships** - продолжительная, крепкая дружба.'],
    ['Pottery is quite difficult to (4) ______ with *(handle)* when you are short of time.', ['deal'], '**Deal with** - справляться с чем-либо.'],
    ['I have already gotten seriously (5) ______ *(got a serious trauma)* twice from falling off the bicycle.', ['injured'], '**Get seriously injured** - серьёзно травмироваться.'],
  ];
  foreach ($synonyms as $index => [$sentence, $correct, $solution]) {
    $questions[] = $textQuestion('Задание 3 · Синонимы · ' . ($index + 1) . '/5', $sentence, $correct, $solution, 'Впиши синоним слова или выражения в скобках так, чтобы получилась естественная коллокация.');
  }

  $prepositions = [
    ['When selecting activities, pay attention (1) ______ what truly interests you.', ['to'], '**Pay attention to** - обращать внимание на.'],
    ['Many teenagers get discouraged (2) ______ continuing hobbies.', ['from'], '**Discourage from doing something** - отбить желание что-либо делать.'],
    ['Hobbies can be a great way to cope (3) ______ stress.', ['with'], '**Cope with** - справляться с.'],
    ['Trying different activities helps you see beauty (4) ______ ordinary things.', ['in'], '**See beauty in** - видеть красоту в.'],
    ['Find something that does not take (5) ______ a lot of your time.', ['up'], '**Take up time** - занимать время.'],
  ];
  foreach ($prepositions as $index => [$sentence, $correct, $solution]) {
    $questions[] = $textQuestion('Задание 4 · Предлоги · ' . ($index + 1) . '/5', $sentence, $correct, $solution, 'Впиши только один подходящий предлог.');
  }

  $sample = "Hi Tanya! I've found two photos for our project “Free time activities”. I'd like to describe them to you.\n\nONE photo depicts a young woman reading a book while sitting outdoors. She might be in a park. As for THE OTHER photo, it shows a family playing a board game on the floor. They are sitting closely, leaning forward towards the game board.\n\nThe key difference is the type of hobbies: the first photo demonstrates a solo pastime, while the second illustrates a social activity. Solo hobbies give complete freedom to set your own pace, but require discipline and may lack social interaction. Social hobbies help strengthen relationships, but can feel overwhelming and require coordination with others.\n\nPersonally, I would prefer reading because I am an introvert and enjoy spending free time in solitude. That's all I wanted to tell you. Call me back as soon as possible.";
  $questions[] = [
    'type' => 'textarea',
    'text' => "Задание 5 · Устная часть · самопроверка\n\nПодготовь ответ для проекта **Free time activities**: опиши чтение и настольные игры, сравни их, назови преимущества и недостатки и объясни свой выбор. Можешь записать голосовое отдельно, а сюда вставить письменный вариант ответа.",
    'options' => null,
    'correct' => [],
    'solution' => "**Пример ответа:**\n\n" . $sample,
    'hint' => 'Соблюдай порядок: вступление → описание двух фото → различия → плюсы → минусы → личное мнение → завершение. Устный ответ должен укладываться в 3 минуты.',
    'optional' => true,
    'points' => 10,
  ];

  return $questions;
}
