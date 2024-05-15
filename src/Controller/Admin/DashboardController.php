<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use App\Controller\Admin\UserCrudController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;

class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        $adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);
        return $this->redirect($adminUrlGenerator->setController(UserCrudController::class)->generateUrl());
    }

    #[Route('/admin/import-users', name: 'admin_import_users')]
    public function importUsers(): Response
    {
        $routeBuilder = $this->container->get(AdminUrlGenerator::class);

        return $this->redirect($routeBuilder->setController(UserCrudController::class)->setAction('importUsers')->generateUrl());
    }
    #[Route('/admin/batch-delete', name: 'admin_batch_delete', methods: ['POST'])]
    public function batchDelete(Request $request, EntityManagerInterface $entityManager): Response
    {
        $ids = $request->request->get('batch_action_ids', []);
        if ($ids) {
            foreach ($ids as $id) {
                $user = $entityManager->getRepository(User::class)->find($id);
                if ($user) {
                    $entityManager->remove($user);
                }
            }
            $entityManager->flush();
        }
        $this->addFlash('success', 'Selected users have been deleted.');
        return $this->redirectToRoute('admin');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('QuizzDWWM');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkToCrud('User', 'fas fa-list', User::class);
        yield MenuItem::linkToRoute('Import Users', 'fas fa-upload', 'admin_import_users');
    }
}
