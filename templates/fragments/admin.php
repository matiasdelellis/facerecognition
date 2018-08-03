<form id="facerecognition">
	<div class="section">
		<h2><?php p($l->t('Face recognition'));?><a target="_blank" rel="noreferrer noopener" class="icon-info" title="<?php p($l->t('Open Documentation'));?>" href="https://github.com/matiasdelellis/facerecognition/wiki"></a></h2>
		<p><strong>Status </strong><?php p($_['msg']); ?><span class="status success<?php if(!$_['status']):?> error<?php endif;?>"></span></p>
	</div>
</form>