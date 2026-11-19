'use strict';

(function () {
  const baseNav = window.nav;
  const baseGo = window.go;
  let activePage = 'home';

  window.nav = function () {
    baseNav();
    if (!session.user) return;
    const button = document.createElement('button');
    button.textContent = '📝 Actividades';
    button.setAttribute('onclick', "go('activities')");
    const nav = $('nav');
    nav.insertBefore(button, nav.children[4] || null);
    nav.querySelectorAll('button').forEach(item => {
      const match = item.getAttribute('onclick')?.match(/go\('([^']+)'\)/);
      if (match?.[1] === activePage) item.setAttribute('aria-current', 'page');
    });
  };

  window.go = async function (page) {
    activePage = page;
    if (page === 'activities') {
      if (!session.user) return login();
      await activityPage();
      $('nav')?.querySelectorAll('button').forEach(item => {
        const match = item.getAttribute('onclick')?.match(/go\('([^']+)'\)/);
        if (match?.[1] === activePage) item.setAttribute('aria-current', 'page');
        else item.removeAttribute('aria-current');
      });
      return;
    }
    const result = await baseGo(page);
    if (page === 'logout') activePage = 'login';
    $('nav')?.querySelectorAll('button').forEach(item => {
      const match = item.getAttribute('onclick')?.match(/go\('([^']+)'\)/);
      if (match?.[1] === activePage) item.setAttribute('aria-current', 'page');
      else item.removeAttribute('aria-current');
    });
    return result;
  };

  window.home = async function () {
    const isAdmin = session.user?.role === 'admin';
    try {
      const [resources, activities, users] = await Promise.all([
        api('resource_list'),
        api('activity_list'),
        isAdmin ? api('users') : Promise.resolve([]),
      ]);
      const activeActivities = activities.filter(activity => activity.active);
      const mySubmissions = activities.reduce((count, activity) => count + activity.submissions.length, 0);
      const completion = overview.progress;
      show(`<section class="hero">
        <p class="eyebrow">${isAdmin ? 'CENTRO DE CONTROL' : 'TU ESPACIO DE APRENDIZAJE'}</p>
        <h1>${isAdmin ? 'Acompañar también es enseñar.' : 'Bienvenido Docente'}</h1>
        <p>${isAdmin
          ? 'Publica recursos, propone nuevas actividades y acompaña el crecimiento de cada docente.'
          : 'Avanza a tu ritmo, explora ideas para el aula y comparte lo que vas aprendiendo.'}</p>
        <button class="btn" onclick="go('${isAdmin ? 'admin' : 'route'}')">${isAdmin ? 'Ir al seguimiento' : 'Continuar mi ruta'}</button>
      </section>
      <section class="dashboard-stats" aria-label="Resumen de formación">
        ${isAdmin
          ? `<article class="stat-card"><span>Docentes en el aula</span><strong>${users.filter(user => user.role === 'teacher').length}</strong><small>cuentas activas</small></article>
             <article class="stat-card"><span>Materiales disponibles</span><strong>${resources.length}</strong><small>documentos y videos</small></article>
             <article class="stat-card"><span>Actividades abiertas</span><strong>${activeActivities.length}</strong><small>para la comunidad docente</small></article>`
          : `<article class="stat-card"><span>Tu avance</span><strong>${completion.percent}<i>%</i></strong><div>${progress(completion.percent)}</div><small>${completion.completed} de 6 módulos completos</small></article>
             <article class="stat-card"><span>Materiales para explorar</span><strong>${resources.length}</strong><small>documentos y videos publicados</small><button class="text-action" onclick="go('resources')">Abrir biblioteca <span aria-hidden="true">→</span></button></article>
             <article class="stat-card"><span>Actividades abiertas</span><strong>${activeActivities.length}</strong><small>${mySubmissions} evidencias entregadas</small><button class="text-action" onclick="go('activities')">Ver mis actividades <span aria-hidden="true">→</span></button></article>`}
      </section>
      <div class="section-heading"><div><p class="eyebrow">SIGUIENTE PASO</p><h2>${isAdmin ? 'Herramientas para acompañar' : 'Un espacio para cada etapa'}</h2></div></div>
      <section class="action-grid">
        <button class="action-card" onclick="go('${isAdmin ? 'resources' : 'route'}')">
          <span class="action-icon">${isAdmin ? '↗' : '01'}</span><strong>${isAdmin ? 'Biblioteca de materiales' : 'Mi ruta de formación'}</strong>
          <span>${isAdmin ? 'Publica documentos, hojas de cálculo y videos para el aula.' : 'Retoma los módulos y microlecciones donde los dejaste.'}</span><b>Explorar <span aria-hidden="true">→</span></b>
        </button>
        <button class="action-card" onclick="go('${isAdmin ? 'activities' : 'evidence'}')">
          <span class="action-icon">${isAdmin ? '✳' : '02'}</span><strong>${isAdmin ? 'Actividades y evidencias' : 'Mis entregas'}</strong>
          <span>${isAdmin ? 'Asigna retos y revisa los trabajos enviados por docentes.' : 'Consulta las evidencias que ya compartiste.'}</span><b>${isAdmin ? 'Gestionar actividades' : 'Ver evidencias'} <span aria-hidden="true">→</span></b>
        </button>
        <button class="action-card" onclick="go('community')">
          <span class="action-icon">${isAdmin ? '◎' : '03'}</span><strong>Comunidad RuralTech</strong>
          <span>Comparte experiencias y aprende junto a otros docentes rurales.</span><b>Entrar a la comunidad <span aria-hidden="true">→</span></b>
        </button>
      </section>`);
    } catch (error) {
      msg(error);
    }
  };

  window.admin = async function () {
    if (session.user?.role !== 'admin') {
      show('<h1>Acceso no autorizado</h1>');
      return;
    }

    try {
      const [users, resources, evaluations] = await Promise.all([
        api('users'),
        api('resource_list'),
        api('evaluations'),
      ]);
      const teachers = users.filter(user => user.role === 'teacher');
      const average = teachers.length
        ? Math.round(teachers.reduce((total, user) => total + user.progress.percent, 0) / teachers.length)
        : 0;
      const completedModules = teachers.reduce((total, user) => total + user.progress.completed, 0);
      const activeActivities = 0; // se calcula en activity_page si se necesita

      window.adminUsers = users;
      window.adminEvals = evaluations;
      show(`<section class="hero">
        <p class="eyebrow">GESTIÓN DE LA COMUNIDAD</p>
        <h1>El aprendizaje crece en comunidad.</h1>
        <p>Organiza el acceso, acompaña el progreso y prepara recursos para los docentes.</p>
        <button class="btn" onclick="go('resources')">Publicar un material</button>
      </section>
      <section class="dashboard-stats" aria-label="Resumen de administración">
        <article class="stat-card"><span>Docentes registrados</span><strong>${teachers.length}</strong><small>cuentas de formación activas</small></article>
        <article class="stat-card"><span>Avance promedio</span><strong>${average}<i>%</i></strong><div>${progress(average)}</div><small>sobre seis módulos</small></article>
        <article class="stat-card"><span>Módulos completados</span><strong>${completedModules}</strong><small>sumados entre docentes</small></article>
      </section>
      <div class="section-heading"><div><p class="eyebrow">HERRAMIENTAS</p><h2>¿Qué quieres hacer?</h2></div></div>
      <section class="action-grid">
        <button class="action-card" onclick="go('resources')"><span class="action-icon">↗</span><strong>Biblioteca de materiales</strong><span>Publica documentos, hojas de cálculo y videos para el aula.</span><b>Gestionar recursos <span aria-hidden="true">→</span></b></button>
        <button class="action-card" onclick="go('activities')"><span class="action-icon">✳</span><strong>Actividades y evidencias</strong><span>Asigna retos y revisa los trabajos enviados por docentes.</span><b>Gestionar actividades <span aria-hidden="true">→</span></b></button>
        <button class="action-card" onclick="go('auditPage')"><span class="action-icon">◎</span><strong>Registro de actividad</strong><span>Consulta los eventos recientes de la plataforma.</span><b>Ver auditoría <span aria-hidden="true">→</span></b></button>
      </section>
      <section class="panel admin-panel">
        <div class="section-heading"><div><p class="eyebrow">ACOMPAÑAMIENTO</p><h2>Seguimiento por docente</h2></div><button class="btn sec" onclick="adminExport()">Exportar CSV</button></div>
        <label for="adminFilter">Buscar por nombre, sede o correo</label>
        <input id="adminFilter" type="search" placeholder="Escribe para filtrar" oninput="adminFilter(this.value)">
        <div class="table"><table><thead><tr><th>Nombre</th><th>Sede</th><th>Correo</th><th>Avance</th><th>Módulos</th><th>Acción</th></tr></thead><tbody>
          ${teachers.map(user => `<tr class="urow" data-search="${esc(`${user.name} ${user.site} ${user.email}`.toLowerCase())}">
            <td>${esc(user.name)}</td><td>${esc(user.site || '—')}</td><td>${esc(user.email)}</td>
            <td>${user.progress.percent}%</td><td>${user.progress.completed}/6</td>
            <td><button class="btn sec" onclick="adminDetail('${esc(user.id)}')">Ver detalle</button> <button class="btn sec" onclick="adminEdit('${esc(user.id)}')">Editar</button> <button class="btn danger" onclick="adminDeleteUser('${esc(user.id)}')">Eliminar</button></td>
          </tr>`).join('') || '<tr><td colspan="6">Todavía no hay docentes registrados.</td></tr>'}
        </tbody></table></div>
      </section>
      <section class="panel admin-panel">
        <div class="section-heading"><div><p class="eyebrow">MATERIALES INSTITUCIONALES</p><h2>Recursos publicados</h2></div>
        ${resources.map(r => {
          const video = /\.(mp4|webm)$/i.test(r.file.name);
          return `<div class="card"><h3>${esc(r.title)}</h3><p>${esc(r.description)}</p>
            ${video ? `<video controls preload="metadata" style="width:100%;max-width:760px" src="api.php?action=download&id=${encodeURIComponent(r.file.id)}&inline=1">El navegador no puede reproducir este video.</video>` : ''}
            <div class="row"><span>${esc(r.file.name)}${r.module === 'all' ? ' · General' : ` · Módulo ${esc(r.module)}`}</span>
              <button class="btn" onclick="fileDownload('${esc(r.file.id)}')">Descargar</button>
              <button class="btn danger" onclick="removePublishedResource('${esc(r.id)}')">Retirar</button>
            </div></div>`;
        }).join('') || '<p>No hay recursos publicados.</p>'}
        <div class="section-heading"><div><p class="eyebrow">Publicar nuevo material</p><h2>Subir recurso</h2></div>
        <label for="resourceTitleNew">Título</label><input id="resourceTitleNew" maxlength="150">
        <label for="resourceDescriptionNew">Descripción</label><textarea id="resourceDescriptionNew" maxlength="1500"></textarea>
        <label for="resourceModuleNew">Módulo</label><select id="resourceModuleNew"><option value="all">Banco general</option>${MODULES.map((_, i) => `<option value="${i}">Módulo ${i}</option>`).join('')}</select>
        <label for="resourceFileNew">Archivo (PDF, Word, Excel, imágenes, video)</label><input type="file" id="resourceFileNew" accept=".pdf,.doc,.docx,.odt,.jpg,.jpeg,.png,.webp,.txt,.rtf,.ppt,.pptx,.xls,.xlsx,.mp4,.webm">
        <p class="muted small">Documentos: máximo 15 MB. Videos MP4/WebM: máximo 100 MB.</p>
        <button class="btn" onclick="publishResource(this)">Publicar material</button>${barHTML('upResource')}
        </section>
      <section class="admin-columns">
        <div class="panel">
          <p class="eyebrow">ACCESO A LA PLATAFORMA</p><h2>Crear cuenta</h2>
          <label for="userName">Nombre completo</label><input id="userName" autocomplete="name">
          <label for="userEmail">Correo</label><input id="userEmail" type="email" autocomplete="email">
          <label for="userSite">Sede educativa</label><input id="userSite">
          <label for="userRole">Rol</label><select id="userRole"><option value="teacher">Docente</option><option value="admin">Administrador</option></select>
          <label for="userPassword">Contraseña inicial (mínimo 12 caracteres)</label><input id="userPassword" type="password" autocomplete="new-password">
          <label for="userPassword2">Repetir contraseña</label><input id="userPassword2" type="password" autocomplete="new-password">
          <button class="btn" onclick="adminCreateUser(this)">Crear cuenta</button>
        </div>
        <div class="panel">
          <p class="eyebrow">VOZ DE LOS DOCENTES</p><h2>Valoraciones recibidas</h2>
          ${evaluations.map(evaluation => {
            const ratings = evaluation.ratings || [];
            const averageRating = ratings.length
              ? (ratings.reduce((sum, rating) => sum + rating, 0) / ratings.length).toFixed(1)
              : '—';
            return `<details><summary>${esc(evaluation.name)} · promedio ${averageRating}/5</summary>
              <p class="muted small">${esc(fmtDate(evaluation.updated))} · ${esc(ROLE_ES[evaluation.role] || evaluation.role)}</p>
              <p>${ratings.map(rating => '★'.repeat(rating) + '☆'.repeat(5 - rating)).join(' · ')}</p>
              <p>${esc(evaluation.comments || 'Sin comentarios')}</p></details>`;
          }).join('') || '<p class="muted">Aún no hay valoraciones.</p>'}
        </div>
        <div class="panel">
          <p class="eyebrow">RESPALDO Y CONTINUIDAD</p><h2>Respaldo completo</h2>
          <p>Descarga los datos, el progreso y los archivos adjuntos de la plataforma.</p>
          <button class="btn sec" onclick="window.open('api.php?action=backup','_blank')">Descargar respaldo integral</button>
          <hr>
          <h3>Restaurar respaldo</h3>
          <p class="notice">Esta acción reemplaza los datos y archivos actuales. Se guarda una copia previa automáticamente.</p>
          <label for="restoreFile">Archivo de respaldo (.tar)</label><input type="file" id="restoreFile" accept=".tar">
          <button class="btn danger" onclick="adminRestore(this)">Restaurar respaldo</button>
        </div>
      </section>`);
    } catch (error) {
      msg(error);
    }
  };

  window.resources = async function () {
    try {
      const resources = await api('resource_list');
      const isAdmin = session.user?.role === 'admin';
      show(`<h1>Banco de materiales didácticos</h1>
        ${isAdmin ? `<div class="panel"><h2>Publicar material</h2>
          <label for="resourceTitleNew">Título</label><input id="resourceTitleNew" maxlength="150">
          <label for="resourceDescriptionNew">Descripción</label><textarea id="resourceDescriptionNew" maxlength="1500"></textarea>
          <label for="resourceModuleNew">Módulo</label><select id="resourceModuleNew"><option value="all">Banco general</option>${MODULES.map((_, i) => `<option value="${i}">Módulo ${i}</option>`).join('')}</select>
          <label for="resourceFileNew">Documento o video (PDF, Word, Excel, MP4 o WebM)</label>
          <input type="file" id="resourceFileNew" accept=".pdf,.doc,.docx,.odt,.jpg,.jpeg,.png,.webp,.txt,.rtf,.ppt,.pptx,.xls,.xlsx,.mp4,.webm">
          <p class="muted small">Documentos: máximo 15 MB. Videos MP4/WebM: máximo 100 MB.</p>
          <button class="btn" onclick="publishResource(this)">Publicar material</button>${barHTML('upResource')}
        </div>` : ''}
        <h2>Materiales publicados</h2>
        ${resources.map(resource => {
          const video = /\.(mp4|webm)$/i.test(resource.file.name);
          return `<div class="card"><h3>${esc(resource.title)}</h3><p>${esc(resource.description)}</p>
            ${video ? `<video controls preload="metadata" style="width:100%;max-width:760px" src="api.php?action=download&id=${encodeURIComponent(resource.file.id)}&inline=1">El navegador no puede reproducir este video.</video>` : ''}
            <div class="row"><span>${esc(resource.file.name)}${resource.module === 'all' ? ' · General' : ` · Módulo ${esc(resource.module)}`}</span>
              <button class="btn" onclick="fileDownload('${esc(resource.file.id)}')">Descargar</button>
              ${isAdmin ? `<button class="btn danger" onclick="removePublishedResource('${esc(resource.id)}')">Retirar</button>` : ''}
            </div></div>`;
        }).join('') || '<p>Aún no hay materiales publicados por administración.</p>'}
        <h2>Guías por módulo</h2>${MODULES.map((m, i) => `<div class="row">Módulo ${i}: ${esc(m.title)} <button class="btn sec" onclick="downloadText('Guia_Modulo_${i}.txt',guide(${i}))">Descargar</button></div>`).join('')}
        <h2>Guías didácticas generales</h2><div class="grid">${MATERIALS.map((m, i) => `<div class="card"><h3>${esc(m[0])}</h3><button class="btn" onclick="downloadText('RuralTech_Material_${i + 1}.txt',MATERIALS[${i}][1])">Descargar</button><button class="btn sec" onclick="viewMaterial(${i})">Leer</button></div>`).join('')}</div>`);
    } catch (error) {
      msg(error);
    }
  };

  window.publishResource = async function (button) {
    busy(button, 'Subiendo…');
    try {
      const file = $('resourceFileNew').files[0];
      await uploadFile(file, {
        kind: 'resource',
        title: $('resourceTitleNew').value,
        description: $('resourceDescriptionNew').value,
        module: $('resourceModuleNew').value,
      }, 'upResource');
      alert('Material publicado para todos los docentes.');
      await resources();
    } catch (error) {
      msg(error);
    } finally {
      unbusy(button);
      hideBar('upResource');
    }
  };

  window.removePublishedResource = async function (id) {
    if (!confirm('¿Retirar este material del banco?')) return;
    try {
      await api('resource_delete', { id });
      await resources();
    } catch (error) {
      msg(error);
    }
  };

  window.activityPage = async function () {
    try {
      const activities = await api('activity_list');
      const isAdmin = session.user?.role === 'admin';
      const completed = activities.filter(activity => activity.submissions.length > 0).length;
      const activeCount = activities.filter(activity => activity.active).length;
      show(`<h1>Actividades de capacitación</h1>
        ${isAdmin ? `<div class="panel"><h2>Publicar actividad para todos los docentes</h2>
          <label for="activityTitle">Título</label><input id="activityTitle" maxlength="150" required>
          <label for="activityDescription">Instrucciones</label><textarea id="activityDescription" maxlength="6000" required placeholder="Describe qué debe realizar el docente y qué evidencia debe entregar."></textarea>
          <label for="activityDue">Fecha límite (opcional)</label><input id="activityDue" type="date">
          <button class="btn" onclick="publishActivity(this)">Publicar actividad</button>
        </div>` : `<div class="grid"><div class="card"><h2>${completed}/${activeCount}</h2>Actividades con evidencia entregada</div><div class="card"><h2>${activeCount}</h2>Actividades disponibles</div></div>`}
        ${activities.map(activity => {
          const due = activity.due ? new Date(`${activity.due}T00:00:00`).toLocaleDateString('es-CO') : '';
          const submissions = activity.submissions || [];
          const latest = isAdmin ? '' : submissions.length ? `<p class="success">Tienes ${submissions.length} entrega(s); última: ${esc(fmtDate(submissions[submissions.length - 1].created))}.</p>` : '<p class="notice">Aún no has entregado evidencia.</p>';
          const adminEvidence = isAdmin ? `<h3>Evidencias recibidas (${submissions.length})</h3>${submissions.map(submission => `<div class="card"><strong>${esc(submission.user.name)}</strong> · ${esc(submission.user.email)}<p class="muted small">${esc(fmtDate(submission.created))}</p>${submission.files.map(file => `<div class="row">${esc(file.name)} <button class="btn sec" onclick="fileDownload('${esc(file.id)}')">Descargar evidencia</button></div>`).join('')}</div>`).join('') || '<p>Aún no hay entregas.</p>'}` : '';
          const teacherSubmit = !isAdmin && activity.active ? `<label for="activityFile-${esc(activity.id)}">Subir evidencia (máximo 15 MB)</label><input type="file" id="activityFile-${esc(activity.id)}" accept=".pdf,.doc,.docx,.odt,.jpg,.jpeg,.png,.webp,.txt,.rtf,.ppt,.pptx,.xls,.xlsx"><button class="btn" onclick="submitActivityEvidence('${esc(activity.id)}',this)">Entregar evidencia</button>${barHTML(`activityBar-${activity.id}`)}${submissions.map(submission => submission.files.map(file => `<div class="row">${esc(file.name)} · ${esc(fmtDate(submission.created))} <button class="btn sec" onclick="fileDownload('${esc(file.id)}')">Descargar</button></div>`).join('')).join('')}` : '';
          return `<div class="panel"><div class="row"><h2>${esc(activity.title)}</h2>${isAdmin && activity.active ? `<button class="btn danger" onclick="archiveActivity('${esc(activity.id)}')">Archivar</button>` : ''}</div>
            ${!activity.active ? '<p class="muted">Archivada</p>' : ''}
            ${due ? `<p><strong>Fecha límite:</strong> ${esc(due)}</p>` : ''}
            <pre>${esc(activity.description)}</pre>${latest}${teacherSubmit}${adminEvidence}</div>`;
        }).join('') || '<div class="panel"><p>Aún no hay actividades publicadas.</p></div>'}`);
    } catch (error) {
      msg(error);
    }
  };

  window.publishActivity = async function (button) {
    busy(button, 'Publicando…');
    try {
      await api('activity_create', {
        title: $('activityTitle').value,
        description: $('activityDescription').value,
        due: $('activityDue').value,
      });
      alert('Actividad publicada para todos los docentes.');
      await activityPage();
    } catch (error) {
      msg(error);
    } finally {
      unbusy(button);
    }
  };

  window.archiveActivity = async function (id) {
    if (!confirm('¿Archivar esta actividad? Se conservarán las evidencias recibidas.')) return;
    try {
      await api('activity_archive', { id });
      await activityPage();
    } catch (error) {
      msg(error);
    }
  };

  window.submitActivityEvidence = async function (id, button) {
    busy(button, 'Subiendo…');
    try {
      const file = $(`activityFile-${id}`).files[0];
      await uploadFile(file, { kind: 'activity_evidence', activity_id: id }, `activityBar-${id}`);
      alert('Evidencia entregada.');
      await activityPage();
    } catch (error) {
      msg(error);
    } finally {
      unbusy(button);
      hideBar(`activityBar-${id}`);
    }
  };

  window.adminEdit = function (id) {
    const user = (window.adminUsers || []).find(item => item.id === id);
    if (!user) { msg(Error('Usuario no encontrado')); return; }
    show(`<button class="btn sec" onclick="admin()">← Administración</button><h1>Editar cuenta</h1>
      <div class="panel">
        <p class="muted small">${esc(ROLE_ES[user.role] || user.role)} · ${esc(user.email)}</p>
        <label for="editName">Nombre completo</label><input id="editName" maxlength="80" value="${esc(user.name)}">
        <label for="editEmail">Correo</label><input id="editEmail" type="email" value="${esc(user.email)}">
        <label for="editSite">Sede educativa</label><input id="editSite" maxlength="100" value="${esc(user.site || '')}">
        <label for="editRole">Rol</label><select id="editRole"><option value="teacher"${user.role === 'teacher' ? ' selected' : ''}>Docente</option><option value="admin"${user.role === 'admin' ? ' selected' : ''}>Administrador</option></select>
        <label for="editPassword">Nueva contraseña (opcional, mínimo 12 caracteres)</label><input id="editPassword" type="password" autocomplete="new-password">
        <button class="btn" onclick="adminSaveUser('${esc(user.id)}', this)">Guardar cambios</button>
      </div>`);
  };

  window.adminSaveUser = async function (id, button) {
    busy(button, 'Guardando…');
    try {
      await api('update_user', {
        user_id: id,
        name: $('editName').value,
        email: $('editEmail').value.trim(),
        site: $('editSite').value,
        role: $('editRole').value,
        password: $('editPassword').value,
      });
      alert('Cuenta actualizada.');
      admin();
    } catch (error) {
      msg(error);
    } finally {
      unbusy(button);
    }
  };

  window.adminDeleteUser = async function (id) {
    const user = (window.adminUsers || []).find(item => item.id === id);
    if (!confirm(`¿Eliminar la cuenta de ${user ? user.name : 'este docente'}? Se borrarán su progreso, entregas y archivos, y no se puede deshacer.`)) return;
    try {
      await api('delete_user', { user_id: id });
      alert('Cuenta eliminada.');
      admin();
    } catch (error) {
      msg(error);
    }
  };
})();
