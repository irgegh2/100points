import { ArrowRight, BookOpen, CheckCircle2, FileUp, FolderTree } from 'lucide-react'

export default function AdminLibrary() {
  const openCourses = () => { window.location.hash = '#courses' }

  return (
    <div className="admin-wrap admin__editor">
      <section className="admin__panel admin-library-auto">
        <div className="admin-library-auto__hero">
          <span className="admin-library-auto__icon"><BookOpen size={27} /></span>
          <div>
            <span className="admin-library-auto__eyebrow">Единая библиотека</span>
            <h2>Собирается автоматически из уроков</h2>
            <p className="admin__hint">
              Отдельно дублировать конспекты и файлы больше не нужно. Ученик выбирает предмет,
              видит названия доступных уроков основных курсов и все материалы, прикреплённые внутри каждого урока.
            </p>
          </div>
        </div>

        <div className="admin-library-auto__steps">
          <div><span>1</span><b>Открой курс</b><small>Выбери нужный предмет и курс.</small></div>
          <div><span>2</span><b>Найди урок</b><small>Период → раздел → нужный урок.</small></div>
          <div><span>3</span><b>Добавь материалы</b><small>Файлы и ссылки появятся в библиотеке сами.</small></div>
        </div>

        <div className="admin-library-auto__rules">
          <div><CheckCircle2 size={17} /><span>Название полки всегда берётся из названия урока.</span></div>
          <div><FolderTree size={17} /><span>Порядок совпадает с программой курса.</span></div>
          <div><FileUp size={17} /><span>Ученик видит только доступные и уже открытые уроки.</span></div>
          <div><CheckCircle2 size={17} /><span>Летняя школа, вводные и другие неосновные курсы в библиотеку не попадают.</span></div>
        </div>

        <button className="btn btn-orange btn-lg" onClick={openCourses}>
          Перейти к курсам <ArrowRight size={17} />
        </button>
      </section>
    </div>
  )
}
