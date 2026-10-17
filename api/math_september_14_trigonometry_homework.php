<?php
// Урок профильной математики 14.09.2026: 16 заданий из «Домашнее задание (2).pdf».
// Ответы и ход решения сверены с «Ответы и решения (2).pdf».
function math_september_14_trigonometry_homework_questions(): array {
  return [
    [
      '№1. Вычислите $32\sqrt{6}\cos\frac{7\pi}{4}\tan\frac{\pi}{6}$.',
      '32',
      '$\cos\frac{7\pi}{4}=\frac{\sqrt2}{2}$, $\tan\frac\pi6=\frac1{\sqrt3}$. Поэтому $32\sqrt6\cdot\frac{\sqrt2}{2}\cdot\frac1{\sqrt3}=32$.',
    ],
    [
      '№2. Вычислите $\tan\pi\cdot\sin\frac\pi3\cdot\operatorname{ctg}\frac{3\pi}{4}$.',
      '0',
      '$\tan\pi=0$, значит всё произведение равно $0$.',
    ],
    [
      '№3. Найдите значение выражения $4\sqrt2\cos\frac\pi4\cos\frac{7\pi}{3}$.',
      '2',
      '$\cos\frac{7\pi}{3}=\cos\frac\pi3=\frac12$, а $\cos\frac\pi4=\frac{\sqrt2}{2}$. Тогда $4\sqrt2\cdot\frac{\sqrt2}{2}\cdot\frac12=2$.',
    ],
    [
      '№4. Найдите значение выражения $-4\sqrt3\cos(-750^\circ)$.',
      '-6',
      '$\cos(-750^\circ)=\cos30^\circ=\frac{\sqrt3}{2}$. Следовательно, $-4\sqrt3\cdot\frac{\sqrt3}{2}=-6$.',
    ],
    [
      '№5. Найдите значение выражения $-18\sqrt2\sin(-135^\circ)$.',
      '18',
      '$\sin(-135^\circ)=-\frac{\sqrt2}{2}$. Поэтому $-18\sqrt2\cdot\left(-\frac{\sqrt2}{2}\right)=18$.',
    ],
    [
      '№6. Найдите значение выражения $12\sin150^\circ\cdot\cos120^\circ$.',
      '-3',
      '$\sin150^\circ=\frac12$ и $\cos120^\circ=-\frac12$. Тогда $12\cdot\frac12\cdot\left(-\frac12\right)=-3$.',
    ],
    [
      '№7. Найдите значение выражения $12\sqrt2\cos(-225^\circ)$.',
      '-12',
      '$\cos(-225^\circ)=\cos225^\circ=-\frac{\sqrt2}{2}$. Значит, $12\sqrt2\cdot\left(-\frac{\sqrt2}{2}\right)=-12$.',
    ],
    [
      '№8. Найдите значение выражения $4\sqrt3\sin(-120^\circ)$.',
      '-6',
      '$\sin(-120^\circ)=-\frac{\sqrt3}{2}$. Тогда $4\sqrt3\cdot\left(-\frac{\sqrt3}{2}\right)=-6$.',
    ],
    [
      '№9. Найдите значение выражения $26\sqrt2\cos\frac\pi4\cos\frac{4\pi}{3}$.',
      '-13',
      '$\cos\frac\pi4=\frac{\sqrt2}{2}$, $\cos\frac{4\pi}{3}=-\frac12$. Поэтому $26\sqrt2\cdot\frac{\sqrt2}{2}\cdot\left(-\frac12\right)=-13$.',
    ],
    [
      '№10. Найдите значение выражения $18\sqrt2\tan\frac\pi4\sin\frac\pi4$.',
      '18',
      '$\tan\frac\pi4=1$, $\sin\frac\pi4=\frac{\sqrt2}{2}$. Тогда $18\sqrt2\cdot1\cdot\frac{\sqrt2}{2}=18$.',
    ],
    [
      '№11. Найдите значение выражения $24\sqrt3\tan(-1020^\circ)$.',
      '72',
      'Тангенс имеет период $180^\circ$: $-1020^\circ+1080^\circ=60^\circ$. Значит, $\tan(-1020^\circ)=\tan60^\circ=\sqrt3$, и $24\sqrt3\cdot\sqrt3=72$.',
    ],
    [
      '№12. Найдите значение выражения $25\sqrt2\sin(-585^\circ)$.',
      '25',
      '$-585^\circ+720^\circ=135^\circ$, поэтому $\sin(-585^\circ)=\sin135^\circ=\frac{\sqrt2}{2}$. Получаем $25\sqrt2\cdot\frac{\sqrt2}{2}=25$.',
    ],
    [
      '№13. Найдите значение выражения $13\sqrt2\sin(-495^\circ)$.',
      '-13',
      '$-495^\circ+360^\circ=-135^\circ$, и $\sin(-135^\circ)=-\frac{\sqrt2}{2}$. Тогда $13\sqrt2\cdot\left(-\frac{\sqrt2}{2}\right)=-13$.',
    ],
    [
      '№14. Найдите значение выражения $48\sqrt2\sin(-405^\circ)$.',
      '-48',
      '$-405^\circ+360^\circ=-45^\circ$, и $\sin(-45^\circ)=-\frac{\sqrt2}{2}$. Тогда $48\sqrt2\cdot\left(-\frac{\sqrt2}{2}\right)=-48$.',
    ],
    [
      '№15. Найдите $\tan\alpha$, если $\cos\alpha=\frac{\sqrt{10}}{10}$ и $\alpha\in\left(\frac{3\pi}{2};2\pi\right)$.',
      '-3',
      'В IV четверти синус отрицателен: $\sin\alpha=-\sqrt{1-\cos^2\alpha}=-\frac{3\sqrt{10}}{10}$. Поэтому $\tan\alpha=\frac{\sin\alpha}{\cos\alpha}=-3$.',
    ],
    [
      '№16. Найдите $3\cos\alpha$, если $\sin\alpha=-\frac{2\sqrt2}{3}$ и $\alpha\in\left(\frac{3\pi}{2};2\pi\right)$.',
      '1',
      'В IV четверти косинус положителен: $\cos\alpha=\sqrt{1-\sin^2\alpha}=\sqrt{1-\frac89}=\frac13$. Следовательно, $3\cos\alpha=1$.',
    ],
  ];
}
