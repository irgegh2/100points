import { useEffect, useRef, useState } from 'react'
import { Camera, Minus, Plus, Star, Lock } from 'lucide-react'
import { profileGet, profileSave, avatarUpload, avatarPreset, AVATAR_PRESETS } from '../api.js'

// рекомендуемое число часов в неделю под желаемый балл (40→3ч … 100→12ч)
const recHours = (score) => Math.round(3 + ((score - 40) / 60) * 9)

export default function Profile({ user, onBack, onSaved }) {
  const [name, setName] = useState(user?.name || '')
  const [birth, setBirth] = useState('')                 // ISO yyyy-mm-dd для нативного календаря
  const [email] = useState(user?.email || '')            // только для просмотра
  const [phone] = useState(user?.phone || '')            // только для просмотра
  const [avatar, setAvatar] = useState('')
  // Предметы для целей — только те, по которым у ученика есть доступный курс (с сервера).
  const [subjects, setSubjects] = useState([])           // [{name, color}]
  const [goals, setGoals] = useState({})                 // { предмет: {score, hours} }
  const [goalSubject, setGoalSubject] = useState('')

  const [busy, setBusy] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const fileRef = useRef(null)

  const active = subjects.find((s) => s.name === goalSubject) || { color: '#3f9bff' }
  const cur = goals[goalSubject] || { score: 93, hours: recHours(93) }

  // Подтягиваем сохранённый профиль с сервера.
  useEffect(() => {
    let alive = true
    profileGet()
      .then((d) => {
        if (!alive) return
        if (d.user) setName(d.user.name || '')
        const p = d.profile
        if (p) {
          if (p.birthdate) setBirth(p.birthdate)
          if (p.avatar) setAvatar(p.avatar)
        }
        // Предметы только те, где есть доступный курс. Под них строим цели (с учётом сохранённых).
        const subs = Array.isArray(d.subjects) ? d.subjects : []
        setSubjects(subs)
        const saved = {}
        if (Array.isArray(p?.goals)) p.goals.forEach((g) => {
          if (g.subject) saved[g.subject] = {
            score: g.target_score != null ? g.target_score : 93,
            hours: g.hours_per_week != null ? g.hours_per_week : recHours(g.target_score ?? 93),
          }
        })
        const m = {}
        subs.forEach((s) => { m[s.name] = saved[s.name] || { score: 93, hours: recHours(93) } })
        setGoals(m)
        if (subs.length) setGoalSubject(subs[0].name)
      })
      .catch(() => { /* не вошли/нет сети — дефолты */ })
    return () => { alive = false }
  }, [])

  // Балл/часы — для ТЕКУЩЕГО предмета.
  const changeScore = (v) => setGoals((g) => ({ ...g, [goalSubject]: { score: v, hours: recHours(v) } }))
  const changeHours = (fn) => setGoals((g) => ({
    ...g, [goalSubject]: { ...g[goalSubject], hours: Math.max(1, Math.min(40, fn(g[goalSubject].hours))) },
  }))

  const pickFile = () => fileRef.current?.click()
  const onFile = async (e) => {
    const file = e.target.files?.[0]
    if (!file) return
    setUploading(true); setError('')
    try {
      const r = await avatarUpload(file)
      setAvatar(r.avatar)
    } catch (err) {
      setError(err.message || 'Не удалось загрузить фото')
    } finally {
      setUploading(false)
      e.target.value = '' // чтобы повторный выбор того же файла сработал
    }
  }

  // Выбор готовой аватарки из набора.
  const choosePreset = async (id) => {
    setError('')
    try {
      const r = await avatarPreset(id)
      setAvatar(r.avatar)
    } catch (err) {
      setError(err.message || 'Не удалось выбрать аватар')
    }
  }

  const save = async () => {
    setBusy(true); setError(''); setSaved(false)
    try {
      const goalsArr = subjects.map((s) => ({
        subject: s.name,
        target_score: goals[s.name]?.score ?? 93,
        hours_per_week: goals[s.name]?.hours ?? recHours(93),
      }))
      // avatar НЕ шлём — им владеет только avatar_upload.php (защита от подмены пути).
      const res = await profileSave({ name, birthdate: birth, goals: goalsArr })
      setSaved(true)
      const newAvatar = res.profile?.avatar ?? avatar
      if (onSaved && res.user) onSaved({ ...res.user, avatar: newAvatar })
      setTimeout(() => { if (onBack) onBack() }, 600)
    } catch (err) {
      setError(err.message || 'Не удалось сохранить')
    } finally {
      setBusy(false)
    }
  }

  const avaStyle = avatar
    ? { backgroundImage: `url(${avatar})`, backgroundSize: 'cover', backgroundPosition: 'center' }
    : undefined

  return (
    <div className="profile">
      <div className="profile__greet">
        <h2>Привет{name ? `, ${name}` : ''}! Спасибо, что учишься в нашей онлайн-школе 👋</h2>
        <p>Заполни профиль — так мы поможем тебе точнее: подберём темы, учтём цель и оставшееся время.</p>
      </div>

      {/* 1. Аватар */}
      <section className="pfcard">
        <div className="pfcard__head"><span className="pfcard__num">1</span><h3>Давай поставим классную аватарку</h3></div>
        <div className="profile__ava-row">
          <span className="profile__ava" style={avaStyle} />
          <div>
            <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/webp" onChange={onFile} style={{ display: 'none' }} />
            <button className="btn btn-blue" onClick={pickFile} disabled={uploading}>
              <Camera size={16} /> {uploading ? 'Загрузка…' : 'Загрузить своё фото'}
            </button>
            <p className="pfcard__sub" style={{ margin: '8px 0 0' }}>JPG, PNG или WEBP, до 5 МБ.</p>
          </div>
        </div>

        <p className="pfcard__sub" style={{ marginTop: 18 }}>…или выбери готовую:</p>
        <div className="profile__presets">
          {AVATAR_PRESETS.map((p) => (
            <button
              key={p.id}
              type="button"
              className={`profile__preset ${avatar === p.url ? 'active' : ''}`}
              onClick={() => choosePreset(p.id)}
              aria-label={`Аватар ${p.id}`}
            >
              <img src={p.url} alt="" />
            </button>
          ))}
        </div>
      </section>

      {/* 2. Анкета */}
      <section className="pfcard">
        <div className="pfcard__head"><span className="pfcard__num">2</span><h3>Давай познакомимся</h3></div>
        <div className="profile__form">
          <label>Имя<input type="text" value={name} onChange={(e) => setName(e.target.value)} placeholder="Имя" /></label>
          <label>Дата рождения
            <input type="date" value={birth} onChange={(e) => setBirth(e.target.value)} max="2025-12-31" />
          </label>
          <label>Телефон <span className="profile__locked"><Lock size={12} /> нельзя изменить</span>
            <input type="text" value={phone || '—'} readOnly disabled />
          </label>
          <label>Электропочта <span className="profile__locked"><Lock size={12} /> нельзя изменить</span>
            <input type="text" value={email || '—'} readOnly disabled />
          </label>
        </div>
        <p className="profile__note">Телефон и почта — это твой логин. Чтобы их сменить, напиши в поддержку.</p>
      </section>

      {/* 3. Цели — свои для каждого предмета */}
      <section className="pfcard">
        <div className="pfcard__head"><span className="pfcard__num">3</span><h3>Время ставить цели</h3></div>
        <p className="pfcard__sub">У каждого предмета — своя цель и часы. Выбери предмет и настрой его.</p>

        {subjects.length === 0 ? (
          <p className="profile__note">Цели появятся, когда у тебя будет доступ хотя бы к одному курсу — попроси администратора открыть курс.</p>
        ) : (
          <>
            <div className="profile__gtabs">
              {subjects.map((s) => (
                <button key={s.name} className={s.name === goalSubject ? 'active' : ''} onClick={() => setGoalSubject(s.name)}>
                  <span className="profile__gdot" style={{ background: s.color }} />{s.name}
                  <small style={{ marginLeft: 6, opacity: 0.6 }}>{goals[s.name]?.score ?? 93}</small>
                </button>
              ))}
            </div>

            <div className="profile__goal">
              <div className="profile__score">
                <div className="profile__label">Желаемый балл · {goalSubject}</div>
                <div className="profile__slider">
                  <div className="profile__slider-fill" style={{ width: `${((cur.score - 40) / 60) * 100}%`, background: `${active.color}33` }} />
                  <div className="profile__slider-thumb" style={{ left: `${((cur.score - 40) / 60) * 100}%`, background: active.color }} />
                  <span className="profile__slider-val">{cur.score} <small>баллов</small></span>
                  <input type="range" min="40" max="100" value={cur.score} onChange={(e) => changeScore(+e.target.value)} />
                </div>
              </div>
              <div className="profile__hours">
                <div className="profile__label">Часов в неделю · авто</div>
                <div className="profile__stepper">
                  <button onClick={() => changeHours((h) => h - 1)}><Minus size={16} /></button>
                  <b>{cur.hours}</b>
                  <button onClick={() => changeHours((h) => h + 1)}><Plus size={16} /></button>
                </div>
              </div>
            </div>
          </>
        )}

        {error && <p className="login__error">{error}</p>}
        <button className="btn btn-blue btn-lg profile__save" onClick={save} disabled={busy}>
          <Star size={16} /> {busy ? 'Сохраняем…' : saved ? 'Сохранено ✓' : 'Сохранить и продолжить'}
        </button>
      </section>
    </div>
  )
}
