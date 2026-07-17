import { useEffect, useState } from 'react'
import { Trash2, Save, Flag, PencilLine, Trophy, ClipboardCheck, Flame, Sunrise, Gem } from 'lucide-react'
import { catList, catUpdate, catDelete } from '../../api.js'

// Иконки достижений (значение в БД → компонент). Совпадает с фронтом ученика.
const ICONS = { flag: Flag, pencil: PencilLine, target: Trophy, clipboard: ClipboardCheck, flame: Flame, sunrise: Sunrise, gem: Gem }
const ICON_OPTS = ['flag', 'pencil', 'target', 'clipboard', 'flame', 'sunrise', 'gem']

export default function AdminAchievements() {
  const [items, setItems] = useState([])

  const load = () => catList('achievements').then((d) => setItems(d.items || []))
  useEffect(() => { load() }, [])

  const patch = (id, k, v) => setItems((a) => a.map((x) => x.id === id ? { ...x, [k]: v } : x))
  const save = (it) => catUpdate('achievements', { id: it.id, title: it.title, description: it.description, icon: it.icon, reward: it.reward, target: it.target }).catch((e) => alert(e.message))
  const del = (it) => confirm(`Удалить достижение «${it.title}»? Прогресс учеников по нему тоже удалится.`) && catDelete('achievements', it.id).then(() => setItems((list) => list.filter((x) => x.id !== it.id)))

  return (
    <section className="admin__panel admin__panel--wide">
      <h2><Trophy size={18} /> Достижения <span className="admin__count">{items.length}</span></h2>
      <p className="admin__hint">Открываются автоматически по действиям ученика. Здесь можно поменять название, описание, иконку, награду и цель у любого. Сами условия (триггеры) зашиты в логике.</p>
      <div className="admin__list">
        {items.map((it) => {
          const Ico = ICONS[it.icon] || Trophy
          return (
            <div className="admin__qcard" key={it.id}>
              <div className="admin__qcard-head">
                <span className="admin__ach-ico"><Ico size={18} /></span>
                <input className="admin__inp" style={{ flex: 1 }} value={it.title} onChange={(e) => patch(it.id, 'title', e.target.value)} placeholder="название" />
                <select className="admin__inp admin__inp--sm" value={it.icon || 'flag'} onChange={(e) => patch(it.id, 'icon', e.target.value)}>{ICON_OPTS.map((i) => <option key={i} value={i}>{i}</option>)}</select>
                <label className="admin__field">💎 <input className="admin__inp admin__inp--n" type="number" value={it.reward ?? 0} onChange={(e) => patch(it.id, 'reward', e.target.value)} /></label>
                <label className="admin__field">цель <input className="admin__inp admin__inp--n" type="number" value={it.target ?? 1} onChange={(e) => patch(it.id, 'target', e.target.value)} /></label>
                <button className="admin__iconbtn" title="Сохранить" onClick={() => save(it)}><Save size={15} /></button>
                <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => del(it)}><Trash2 size={15} /></button>
              </div>
              <input className="admin__inp" value={it.description || ''} onChange={(e) => patch(it.id, 'description', e.target.value)} placeholder="описание" />
            </div>
          )
        })}
      </div>
    </section>
  )
}
