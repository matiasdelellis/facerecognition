<form id="facerecognition">
	<div class="section">
		<h2><?php p($l->t('Face recognition'));?><a target="_blank" rel="noreferrer noopener" class="icon-info" title="<?php p($l->t('Open Documentation'));?>" href="https://github.com/matiasdelellis/facerecognition/wiki"></a></h2>
		<p><strong>Status: </strong><?php p($_['msg']); ?><span class="status success<?php if(!$_['status']):?> error<?php endif;?>"></span></p>
		<p><strong>Dlib Version: </strong><?php p($_['dlib-version']); ?><span class="status success<?php if(version_compare($_['dlib-version'], '19.5.0', '<' )):?> error<?php endif;?>"></span></p>
		<p><strong>Dlib CUDA Support: </strong><?php p($_['cuda-support']); ?> </p>
		<p><strong>Dlib AVX Support: </strong><?php p($_['avx-support']); ?></p>
		<p><strong>Dlib NEON Support: </strong><?php p($_['neon-support']); ?></p>
	</div>
</form>