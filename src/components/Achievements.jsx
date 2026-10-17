import { useEffect, useState } from 'react'
import { Flag, PencilLine, Flame, Trophy, Target, Sunrise, Gem, ClipboardCheck, Lock, Check, BookOpen, Layers3, Crown, Star, Rocket, Medal, CalendarCheck2 } from 'lucide-react'
import { achievementsList } from '../api.js'
import { pluralRu } from '../ruPlural.js'
import { useLiveRefresh } from '../liveUpdates.js'

// Иконка и цвет по строке из БД.
const ICONS = { flag: Flag, pencil: PencilLine, target: Target, clipboard: ClipboardCheck, flame: Flame, sunrise: Sunrise, gem: Gem, book: BookOpen, layers: Layers3, crown: Crown, star: Star, rocket: Rocket, medal: Medal, calendar: CalendarCheck2 }
const COLORS = { flag: '#2f6bff', pencil: '#ff7a00', target: '#f5b301', clipboard: '#34c759', flame: '#ff5a1f', sunrise: '#e0518a', gem: '#0ea5a5', book: '#6c5ce7', layers: '#00a8a8', crown: '#d89b00', star: '#8d5be8', rocket: '#ef476f', medal: '#2578d8', calendar: '#1f9d62' }

export default function Achievements() {
  const [ach, setAch] = useState([])
  const [stat, setStat] = useState({ unlocked: 0, total: 0 })
  const [loading, setLoading] = useState(true)

  const reload = () => {
    achievementsList()
      .then((d) => { setAch(d.achievements || []); setStat({ unlocked: d.unlocked, total: d.total }) })
      .catch(() => {})
      .finally(() => setLoading(false))
  }
  useEffect(() => { reload() }, [])
  useLiveRefresh(reload)

  if (loading) return <div className="ach"><div className="hw__empty">Загрузка…</div></div>
  const pct = stat.total ? Math.round((stat.unlocked / stat.total) * 100) : 0

  return (
    <div className="ach">
      <div className="ach__head">
        <div className="ach__count"><b>{stat.unlocked}</b> из {stat.total} открыто</div>
        <div className="ach__bar"><span style={{ width: pct + '%' }} /></div>
      </div>

      <div className="ach__grid">
        {ach.map((a) => {
          const Ico = ICONS[a.icon] || Trophy
          const color = COLORS[a.icon] || '#2f6bff'
          const inProgress = !a.unlocked && a.progress > 0
          const state = a.unlocked ? 'on' : inProgress ? 'progress' : 'off'
          return (
            <div className={`ach__card is-${state}`} key={a.code}>
              <span className="ach__ico" style={a.unlocked ? { background: color } : undefined}>
                <Ico size={26} />
                {state === 'off' && <span className="ach__lock"><Lock size={12} /></span>}
                {state === 'on' && <span className="ach__check"><Check size={12} /></span>}
              </span>
              <div className="ach__title">{a.title}</div>
              <div className="ach__desc">{a.description}</div>

              {!a.unlocked && a.target > 1 && (
                <div className="ach__prog">
                  <div className="ach__prog-bar"><span style={{ width: Math.min(100, Math.round((a.progress / a.target) * 100)) + '%', background: color }} /></div>
                  <span className="ach__prog-num">{a.progress} / {a.target}</span>
                </div>
              )}
              {a.unlocked ? <div className="ach__got">Получено · +{a.reward} <Gem size={11} /></div> : <div className="ach__desc" style={{ opacity: .7 }}>Награда: +{a.reward} {pluralRu(a.reward, 'кристалл', 'кристалла', 'кристаллов')}</div>}
            </div>
          )
        })}
      </div>
    </div>
  )
}
