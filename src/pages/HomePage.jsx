import { useState } from 'react'
import { Lightbulb, Compass, Search, GraduationCap,
  Calculator, BookOpen, Atom, Users, Leaf, Code, Feather, FlaskConical, Languages, Landmark, Globe } from 'lucide-react'
import Header from '../components/Header.jsx'
import Footer from '../components/Footer.jsx'
import PhoneInput from '../components/PhoneInput.jsx'
import { leadCreate } from '../api.js'
import * as C from '../data/content.js'

/* Состояние и отправка лид-формы. source — какая форма (для админки). */
function useLeadForm(source) {
  const [name, setName] = useState('')
  const [grade, setGrade] = useState('')
  const [phone, setPhone] = useState('')
  const [status, setStatus] = useState('') // '' | 'sending' | 'ok' | <текст ошибки>
  const submit = async (e) => {
    if (e) e.preventDefault()
    if (status === 'sending') return
    setStatus('sending')
    try {
      await leadCreate({ name, grade, phone, source })
      setStatus('ok'); setName(''); setGrade(''); setPhone('')
    } catch (err) { setStatus(err.message || 'Не удалось отправить заявку') }
  }
  return { name, setName, grade, setGrade, phone, setPhone, status, submit }
}

const GRADES = ['5–8 класс', '9 класс', '10 класс', '11 класс']

/* Поля лид-формы (имя/класс/телефон/кнопка) + статус. Переиспользуется во всех блоках. */
function LeadFields({ f, button, btnCls = 'btn btn-orange btn-lg', selectStyle }) {
  if (f.status === 'ok') {
    return <div className="leadform__done">Заявка принята! Менеджер скоро свяжется с тобой по телефону 🙂</div>
  }
  return (
    <>
      <input type="text" placeholder="Имя" value={f.name} onChange={(e) => f.setName(e.target.value)} />
      <select value={f.grade} onChange={(e) => f.setGrade(e.target.value)} style={selectStyle}>
        <option value="" disabled>В каком классе учишься?</option>
        {GRADES.map((g) => <option key={g}>{g}</option>)}
      </select>
      <PhoneInput placeholder="Телефон" value={f.phone} onChange={f.setPhone} />
      <button className={btnCls} type="submit" disabled={f.status === 'sending'}>
        {f.status === 'sending' ? 'Отправляем…' : button}
      </button>
      {f.status && f.status !== 'sending' && <div className="leadform__err">{f.status}</div>}
    </>
  )
}

/* Лид-форма (Имя / класс / телефон) — используется в нескольких блоках */
function LeadForm({ variant = 'leadform' }) {
  const cls = variant === 'leadform' ? 'leadform__grid' : ''
  const f = useLeadForm(variant === 'leadform' ? 'hero' : variant)
  return (
    <form className={variant} onSubmit={f.submit}>
      <div className={cls}>
        <LeadFields f={f} button="Подобрать курс" />
      </div>
    </form>
  )
}

function PromoBar() {
  return (
    <div className="promo">
      <div className="container promo__row">
        <span className="promo__text">{C.promo.text}</span>
        <span className="promo__sub">{C.promo.sub}</span>
      </div>
    </div>
  )
}

function Hero() {
  return (
    <section className="hero">
      <div className="container hero__inner">
        <img className="hero__photo hero__photo--left" src="/img/ilich.webp" alt="Преподаватель" />
        <img className="hero__photo hero__photo--right" src="/img/katya.webp" alt="Преподаватель" />
        <div className="hero__badge"><b>№1</b><i>по результатам учеников</i></div>
        <div className="hero__content">
          <h1>
            {C.hero.title.map((ln, i) => (
              <span key={i} style={{ display: 'block' }}>
                {ln === C.hero.highlight ? <span className="hl">{ln}</span> : ln}
              </span>
            ))}
          </h1>
          <p className="hero__text">{C.hero.text}</p>
          <span className="hero__pill">{C.hero.pill}</span>
        </div>
      </div>
    </section>
  )
}

function HeroForm() {
  return (
    <section id="lead" className="heroform">
      <div className="container"><LeadForm /></div>
    </section>
  )
}

function PreReg() {
  return (
    <section className="prereg" id="prereg">
      <div className="container prereg__inner">
        <div className="prereg__text">
          <h2>{C.preReg.title.map((ln, i) => <span key={i}>{ln}</span>)}</h2>
          <ul>
            {C.preReg.bullets.map((b, i) => {
              const [pre, post] = b.text.split(b.hl)
              return (
                <li key={i}>{pre}<b>{b.hl}</b>{post}</li>
              )
            })}
          </ul>
          <div className="prereg__btns">
            <a className="btn btn-blue" href={C.preReg.vkUrl} target="_blank" rel="noopener noreferrer">Перейти в ВК</a>
            <a className="btn btn-tg" href={C.preReg.tgUrl} target="_blank" rel="noopener noreferrer">Перейти в Телеграм</a>
          </div>
        </div>
        <div className="prereg__art"><img src="/img/prereg.webp" alt="Ученики" /></div>
      </div>
    </section>
  )
}

function Stats() {
  return (
    <section className="section">
      <div className="container">
        <div className="stats__banner">{C.stats.headlineTop} — <u>{C.stats.headlineAccent}</u></div>
        <h3 className="stats__head">С нами ты получишь <span className="mark">высокие баллы</span> на ОГЭ и ЕГЭ</h3>
        <div className="stats__video">
          <video src="/img/stats.mp4" autoPlay muted loop playsInline preload="metadata" />
          <div className="stats__overlay">
            {C.stats.items.map((it) => (
              <div className="stats__item" key={it.value}>
                <b>{it.value}</b><span>{it.label}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </section>
  )
}

function Consult() {
  const f = useLeadForm('consult')
  return (
    <section className="consult" id="consult">
      <img className="consult__photo" src="/img/consult.webp" alt="" />
      <div className="container consult__inner">
        <div className="consult__text">
          <h2>{C.consult.title}</h2>
          <p className="consult__sub">{C.consult.sub}</p>
          <ul>{C.consult.bullets.map((b) => <li key={b}>{b}</li>)}</ul>
        </div>
        <form className="consult__form" onSubmit={f.submit}>
          <LeadFields f={f} button="Подобрать курс" btnCls="btn btn-orange btn-lg btn-block" />
        </form>
      </div>
    </section>
  )
}

const PROBLEM_ICONS = { bulb: Lightbulb, compass: Compass, search: Search, cap: GraduationCap }
const ProblemIcon = ({ name }) => {
  const Ico = PROBLEM_ICONS[name]
  return Ico ? <Ico color="#fff" strokeWidth={1.6} /> : null
}

function ProblemCards() {
  return (
    <section className="section">
      <div className="container">
        <div className="problem__head">
          <h2 className="section-title">Это про тебя? <span className="mark-orange">Тогда залетай</span> в 100балльный</h2>
          <div className="problem__btns">
            <a className="btn btn-orange" href="#courses">Подобрать курс</a>
            <a className="btn btn-light" href="#courses">Выбрать курс самому</a>
          </div>
        </div>
        <div className="problem__grid">
          {C.problemCards.cards.map((c, i) => (
            c.type === 'photo' ? (
              <div className="pcard pcard--photo" key={i}>
                <img src={c.img} alt="" />
              </div>
            ) : (
              <div className={`pcard pcard--icon ${c.color}`} key={i}>
                <div className="picon"><ProblemIcon name={c.icon} /></div>
                <b>{c.text}</b>
              </div>
            )
          ))}
        </div>
      </div>
    </section>
  )
}

const SUBJECT_ICONS = { calc: Calculator, book: BookOpen, atom: Atom, users: Users, leaf: Leaf, code: Code, feather: Feather, flask: FlaskConical, lang: Languages, landmark: Landmark, globe: Globe, cap: GraduationCap }
const SUBJECT_COLORS = ['#ff7a00', '#2f6bff', '#5b4be0', '#34c759', '#e0518a', '#0ea5a5', '#7c3aed', '#f0913a', '#2aa0c9', '#c0392b', '#16a34a', '#d9981f']

function Teachers() {
  const { levels, grades, subjects } = C.teachers
  const [level, setLevel] = useState(levels[0])
  const [grade, setGrade] = useState(grades[0])
  const [subjIdx, setSubjIdx] = useState(0)
  const subject = subjects[subjIdx]
  const accent = SUBJECT_COLORS[subjIdx % SUBJECT_COLORS.length]
  const Ico = SUBJECT_ICONS[subject.icon]

  return (
    <section className="section" id="teachers">
      <div className="container">
        <h2 className="section-title">{C.teachers.title}</h2>
        <div className="teachers__grid">
          <div className="teachers__left">
            <div className="teachers__levels">
              {levels.map((l) => (
                <button key={l} className={l === level ? 'active' : ''} onClick={() => setLevel(l)}>{l}</button>
              ))}
            </div>
            <div className="teachers__grades">
              {grades.map((g) => (
                <button key={g} className={g === grade ? 'active' : ''} onClick={() => setGrade(g)}>{g}</button>
              ))}
            </div>
            <div className="teachers__subjects">
              {subjects.map((s, i) => {
                const SIco = SUBJECT_ICONS[s.icon]
                return (
                  <button key={s.name} className={i === subjIdx ? 'active' : ''} onClick={() => setSubjIdx(i)}
                    style={i === subjIdx ? { '--accent': SUBJECT_COLORS[i % SUBJECT_COLORS.length] } : undefined}>
                    <SIco size={18} /> <span>{s.name}</span>
                  </button>
                )
              })}
            </div>
          </div>

          <div className="teachers__card" style={{ '--accent': accent }} key={subjIdx}>
            <div className="teachers__card-glow" />
            <div className="teachers__card-head">
              <div className="teachers__card-ico"><Ico size={64} strokeWidth={1.5} /></div>
              <div>
                <div className="teachers__card-subject">{subject.name}</div>
                <div className="teachers__card-teacher">{subject.teacher}</div>
                <div className="teachers__card-meta">{subject.edu} · {subject.exp}</div>
              </div>
              <span className="teachers__score">{subject.score}</span>
            </div>

            <div className="teachers__chips">
              <span className="teachers__chip">{level}</span>
              <span className="teachers__chip">{grade}</span>
            </div>

            <div className="teachers__counters">
              {subject.counters.map((c) => (
                <div className="teachers__counter" key={c.l}>
                  <b>{c.v}</b><span>{c.l}</span>
                </div>
              ))}
            </div>

            <ul className="teachers__facts">
              {subject.facts.map((f) => <li key={f}>{f}</li>)}
            </ul>

            <div className="teachers__card-btns">
              <a className="btn btn-orange" href="#courses">Выбрать курс</a>
              <a className="btn btn-light" href="#courses">Подробнее</a>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}

function HowItWorks() {
  return (
    <section className="how section" id="how">
      <div className="container">
        <h2 className="section-title">{C.howItWorks.title}</h2>
        <div className="how__grid">
          {C.howItWorks.features.map((f, i) => (
            <div className="how__card" key={f.name}>
              <div className="n">0{i + 1}</div>
              <h4>{f.name}</h4>
              <p>{f.text}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

function Goals() {
  return (
    <section className="section" id="goals">
      <div className="container">
        <h2 className="section-title">{C.goals.title}</h2>
        <div className="goals__grid">
          <div className="goals__list">
            {C.goals.list.map((g, i) => (
              <a className="goal-item" href="#courses" key={i}>
                <span>{g.title}</span><span className="grade">{g.grade}</span>
              </a>
            ))}
          </div>
          <div className="goals__feature">
            <span className="tag">{C.goals.feature.tag}</span>
            <h3>{C.goals.feature.title}</h3>
            <p>{C.goals.feature.text}</p>
            <a className="btn btn-light" href="#lead" style={{ width: 'max-content' }}>Начать подготовку</a>
          </div>
        </div>
      </div>
    </section>
  )
}

function Courses() {
  return (
    <section className="section" id="courses">
      <div className="container">
        <div className="courses__head">
          <h2 className="section-title">{C.courses.title}</h2>
          <a className="btn btn-orange" href="#lead">Все курсы</a>
        </div>
        <div className="courses__grid">
          {C.courses.list.map((c, i) => (
            <article className="ccard" key={i}>
              <div className="ccard__media">
                {c.img ? <img src={c.img} alt={c.title} /> : <span className="label">Курс</span>}
              </div>
              <div className="ccard__body">
                <div className="ccard__tags">{c.tags.map((tg) => <span key={tg}>{tg}</span>)}</div>
                <h4>{c.title}</h4>
                <div className="ccard__meta">
                  <span>Старт {c.start}</span><span>{c.duration}</span><span>{c.lessons}</span>
                </div>
                <div className="ccard__price">от {c.price} <small>/ курс</small></div>
                <div className="ccard__btns">
                  <a className="btn btn-orange" href="#lead">Выбрать тариф</a>
                  <a className="btn btn-light" href="#courses">О курсе</a>
                </div>
              </div>
            </article>
          ))}
        </div>
      </div>
    </section>
  )
}

function FounderQuote() {
  return (
    <section className="quote section">
      <div className="container">
        <blockquote className="quote__card">
          <p>«{C.founderQuote.text}»</p>
          <cite>{C.founderQuote.author}</cite>
        </blockquote>
      </div>
    </section>
  )
}

function Reviews() {
  return (
    <section className="section" id="reviews">
      <div className="container">
        <h2 className="section-title">{C.reviews.title}</h2>
        <div className="reviews__masonry">
          {C.reviews.list.map((r, i) => (
            <div className="rcard" key={i}>
              <div className="rcard__top">
                <img className="rcard__ava" src={r.ava} alt={r.name} />
                <div className="rcard__who">
                  <div className="rcard__name">{r.name}</div>
                  <div className="rcard__meta">{r.meta}</div>
                </div>
                <span className="rcard__score">{r.score}</span>
              </div>
              <p className="rcard__text">{r.text}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

function Comparison() {
  return (
    <section className="section">
      <div className="container">
        <h2 className="section-title">{C.comparison.title}</h2>
        <div className="compare__grid">
          {C.comparison.columns.map((col, i) => (
            <div className={`compare__col ${col.highlighted ? 'hi' : ''}`} key={i}>
              <h4>{col.name}</h4>
              <ul>{col.points.map((p) => <li key={p}>{p}</li>)}</ul>
              <div className="compare__price">{col.price}</div>
              <p className="compare__summary">{col.summary}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

function Faq() {
  const [open, setOpen] = useState(0)
  return (
    <section className="section" id="faq">
      <div className="container">
        <h2 className="section-title">Если остались <span className="mark-orange">вопросы</span></h2>
        <div className="faq__list">
          {C.faq.items.map((it, i) => (
            <div className={`faq__item ${open === i ? 'open' : ''}`} key={i}>
              <button className="faq__q" onClick={() => setOpen(open === i ? -1 : i)}>
                {it.q}<span className="chev">⌄</span>
              </button>
              <div className="faq__a"><p>{it.a}</p></div>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

function FinalCta() {
  const f = useLeadForm('finalcta')
  return (
    <section className="finalcta">
      <div className="container finalcta__inner">
        <div>
          <h2>Запишись на <span className="mark">вводный урок</span><span className="badge">{C.finalCta.badge}</span></h2>
          <p className="finalcta__text">{C.finalCta.text}</p>
        </div>
        <form className="finalcta__form" onSubmit={f.submit}>
          <LeadFields f={f} button="Записаться" btnCls="btn btn-orange btn-lg btn-block"
            selectStyle={{ height: 50, border: '1.5px solid var(--line)', borderRadius: 12, padding: '0 16px' }} />
        </form>
      </div>
    </section>
  )
}

export default function HomePage() {
  return (
    <>
      <PromoBar />
      <Header />
      <Hero />
      <HeroForm />
      <PreReg />
      <Stats />
      <Consult />
      <ProblemCards />
      <Teachers />
      <HowItWorks />
      <Goals />
      <Courses />
      <FounderQuote />
      <Reviews />
      <Comparison />
      <Faq />
      <FinalCta />
      <Footer />
    </>
  )
}
