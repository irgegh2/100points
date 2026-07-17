import { Construction } from 'lucide-react'

// Заглушка для разделов, которые появятся на своём этапе роадмапа.
export default function AdminStub({ label, stage }) {
  return (
    <div className="admin__stubview">
      <Construction size={40} />
      <h2>{label}</h2>
      <p>Управление этим разделом появится на <b>этапе {stage}</b> — когда будет готова соответствующая часть платформы.</p>
    </div>
  )
}
