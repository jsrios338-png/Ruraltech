'use strict';

(function () {
  const baseNav = window.nav;
  const baseGo = window.go;

  window.nav = function () {
    baseNav();
    if (!session.user) return;
    const button = document.createElement('button');
    button.textContent = '📝 Actividades';
    button.onclick = () => go('activities');
    $('nav').append(button);
  };

  window.go = async function (page) {
    if (page === 'activities') {
      if (!session.user) return login();
      await activityPage();
      return;
    }
    return baseGo(page);
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
})();
