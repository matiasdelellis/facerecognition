<form id="facerecognition">
	<div class="section">
		<h2>
			<?php p($l->t('Face recognition'));?>
			<a target="_blank" rel="noreferrer noopener" class="icon-info" title="<?php p($l->t('Open Documentation'));?>" href="https://github.com/matiasdelellis/facerecognition/wiki"></a>
		</h2>
		<p><strong>Requirements: </strong><?php p($_['msg']); ?><span class="status success<?php if(!$_['requirements']):?> error<?php endif;?>"></span></p>
		<br>
		<p><strong>Dlib Version: </strong><?php p($_['dlib-version']); ?> </p>
		<p><strong>Dlib CUDA Support: </strong><?php p($_['cuda-support']); ?> </p>
		<p><strong>Dlib AVX Support: </strong><?php p($_['avx-support']); ?></p>
		<p><strong>Dlib NEON Support: </strong><?php p($_['neon-support']); ?></p>
		<br>
		<p><strong>Recognition Model: </strong><?php p($_['recognition-model']); ?></p>
		<p><strong>Landmarking Model: </strong><?php p($_['landmarking-model']); ?></p>
	</div>
</form>